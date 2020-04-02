<?php
namespace Institution\Model\Table;

use App\Model\Table\ControllerActionTable;
use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetInterface;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\ResultSet;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;
use function Psy\debug;


class StudentsTable extends ControllerActionTable
{
    const PENDING_TRANSFERIN = -1;
    const PENDING_TRANSFEROUT = -2;
    const PENDING_ADMISSION = -3;
    const PENDING_WITHDRAW = -4;

    private $dashboardQuery = null;

    public function initialize(array $config)
    {
        $this->table('institution_students');
        parent::initialize($config);

        // Associations
        $this->belongsTo('Users', ['className' => 'Security.Users', 'foreignKey' => 'student_id']);
        $this->belongsTo('StudentStatuses', ['className' => 'Student.StudentStatuses']);
        $this->belongsTo('EducationGrades', ['className' => 'Education.EducationGrades']);
        $this->belongsTo('Institutions', ['className' => 'Institution.Institutions', 'foreignKey' => 'institution_id']);
        $this->belongsTo('AcademicPeriods', ['className' => 'AcademicPeriod.AcademicPeriods']);
        $this->belongsTo('PreviousInstitutionStudents', ['className' => 'Institution.Students', 'foreignKey' => 'previous_institution_student_id']);

        // Behaviors
        $this->addBehavior('Year', ['start_date' => 'start_year', 'end_date' => 'end_year']);
        $this->addBehavior('AcademicPeriod.Period');
        $this->addBehavior('User.User');
        $this->addBehavior('User.AdvancedNameSearch');
        $this->addBehavior('Institution.StudentCascadeDelete'); // for cascade delete on student related tables from an institution
        $this->addBehavior('AcademicPeriod.AcademicPeriod'); // to make sure it is compatible with v4

        $this->addBehavior('Excel', [
            'excludes' => ['start_year', 'end_year', 'previous_institution_student_id'],
            'pages' => ['index'],
            'autoFields' => false,
        ]);

        $this->addBehavior('HighChart', [
            'number_of_students_by_year' => [
                '_function' => 'getNumberOfStudentsByYear',
                'chart' => ['type' => 'column', 'borderWidth' => 1],
                'xAxis' => ['title' => ['text' => __('Years')]],
                'yAxis' => ['title' => ['text' => __('Total')]],
            ],
            'number_of_students_by_stage' => [
                '_function' => 'getNumberOfStudentsByStage',
                'chart' => ['type' => 'column', 'borderWidth' => 1],
                'xAxis' => ['title' => ['text' => __('Education')]],
                'yAxis' => ['title' => ['text' => __('Total')]],
            ],
            'institution_student_gender' => [
                '_function' => 'getNumberOfStudentsByGender',
            ],
            'institution_student_age' => [
                '_function' => 'getNumberOfStudentsByAge',
            ],
            'institution_class_student_grade' => [
                '_function' => 'getNumberOfStudentsByGradeByInstitution',
            ],
        ]);
        $this->addBehavior('Import.ImportLink');

        /**
         * Advance Search Types.
         * AdvanceSearchBehavior must be included first before adding other types of advance search.
         * If no "belongsTo" relation from the main model is needed, include its foreign key name in AdvanceSearch->exclude options.
         */
        $advancedSearchFieldOrder = [
            'first_name', 'middle_name', 'third_name', 'last_name',
            'contact_number', 'identity_type', 'identity_number',
        ];

        $this->addBehavior('AdvanceSearch', [
            'exclude' => [
                'student_id',
                'institution_id',
                'education_grade_id',
                'academic_period_id',
                'student_status_id',
                'previous_institution_student_id',
            ],
            'order' => $advancedSearchFieldOrder,
        ]);

        $this->addBehavior('User.AdvancedIdentitySearch', [
            'associatedKey' => $this->aliasField('student_id'),
        ]);
        $this->addBehavior('User.AdvancedContactNumberSearch', [
            'associatedKey' => $this->aliasField('student_id'),
        ]);
        $this->addBehavior('User.AdvancedSpecificNameTypeSearch', [
            'modelToSearch' => $this->Users,
        ]);
        /**
         * End Advance Search Types
         */
        $this->addBehavior('ControllerAction.Image'); // To be verified
        if (!in_array('Risks', (array) Configure::read('School.excludedPlugins'))) {
            $this->addBehavior('Risk.Risks');
        }
    }

    public function implementedEvents()
    {
        $events = parent::implementedEvents();
        $events['Model.InstitutionStudentRisks.calculateRiskValue'] = 'institutionStudentRiskCalculateRiskValue';
        $events['ControllerAction.Model.getSearchableFields'] = ['callable' => 'getSearchableFields', 'priority' => 5];
        return $events;
    }

    public function getSearchableFields(Event $event, ArrayObject $searchableFields)
    {
        $searchableFields[] = 'student_id';
        $searchableFields[] = 'openemis_no';
    }


    public function validationDefault(Validator $validator)
    {
//        dd($validator);
        $validator = parent::validationDefault($validator);

        $validator
            ->add('start_date', 'ruleCompareDate', [
                'rule' => ['compareDate', 'end_date', false],
            ])
            ->add('end_date', [
            ])
            ->add('student_status_id', [
            ])
            ->add('academic_period_id', [
            ])
            ->allowEmpty('student_name')
            ->add('student_name', 'ruleStudentNotEnrolledInAnyInstitutionAndSameEducationSystem', [
                'rule' => ['studentNotEnrolledInAnyInstitutionAndSameEducationSystem', []],
                'on' => 'create',
                'last' => true,
            ])
            ->add('student_name', 'ruleStudentNotCompletedGrade', [
                'rule' => ['studentNotCompletedGrade', []],
                'on' => 'create',
                'last' => true,
            ])
            ->add('student_name', 'ruleCheckAdmissionAgeWithEducationCycleGrade', [
                'rule' => ['checkAdmissionAgeWithEducationCycleGrade'],
                'on' => 'create',
            ])
            ->add('admission_id', [
                'minLength' => [
                    'rule' => ['minLength', 4],
                    'message' => 'Admission number must be of 4 characters long',
                ],
                'maxLength' => [
                    'rule' => ['maxLength', 12],
                    'message' => 'Admission number must be of 12 characters long',
                ],
                'validNumber' => [
                    'rule' => array('custom', '/^[a-z\d]+(?:\/[a-z\d]+)+$/i'),
                    'message' => 'Must contain letters , numbers and "/" only '
                ],
                'ruleNotEmpty' => [
                    'rule' => ['notEmpty'],
                    'message' => "Admission number can't  left empty",
                ],
            ])
            ->add('gender_id', 'rulecompareStudentGenderWithInstitution', [
                'rule' => ['compareStudentGenderWithInstitution'],
            ])
            ->add('education_grade_id', 'ruleCheckProgrammeEndDate', [
                'rule' => ['checkProgrammeEndDate', 'education_grade_id'],
                'on' => 'create',
            ])
            ->add('start_date', 'ruleCheckProgrammeEndDateAgainstStudentStartDate', [
                'rule' => ['checkProgrammeEndDateAgainstStudentStartDate', 'start_date'],
                'on' => 'create',
            ])
        ;
        return $validator;
    }

    // to be verified
    public function validationNewStudent(Validator $validator)
    {
        $validator = $this->validationDefault($validator);
        $validator = $validator->remove('student_name');
        return $validator;
    }

    public function onExcelBeforeQuery(Event $event, ArrayObject $settings, Query $query)
    {
        $institutionId = $this->Session->read('Institution.Institutions.id');

        $Classes = TableRegistry::get('Institution.InstitutionClasses');
        $ClassStudents = TableRegistry::get('Institution.InstitutionClassStudents');
        $periodId = $this->request->query['academic_period_id'];

        $query
            ->where([$this->aliasField('institution_id') => $institutionId])
            ->contain([
                'Users.Genders',
                'Institutions',
                'StudentStatuses',
                'EducationGrades',
                'AcademicPeriods',
                'Users.MainNationalities',
            ])
            ->select([
                'openemis_no' => 'Users.openemis_no',
                'identity_number' => 'Users.identity_number',
                'gender_name' => 'Genders.name',
                'date_of_birth' => 'Users.date_of_birth',
                'code' => 'Institutions.code',
                'institution_name' => 'Institutions.name',
                'student_status' => 'StudentStatuses.name',
                'education_grade' => 'EducationGrades.name',
                'academic_period' => 'AcademicPeriods.name',
                'start_date' => $this->aliasField('start_date'),
                'end_date' => $this->aliasField('end_date'),
                'previous_institution_student_id' => $this->aliasField('previous_institution_student_id'),
                'student_first_name' => 'Users.first_name',
                'student_middle_name' => 'Users.middle_name',
                'student_third_name' => 'Users.third_name',
                'student_last_name' => 'Users.last_name',
                'nationalities' => 'MainNationalities.name',
                'class_name' => 'InstitutionClasses.name',
            ])
            ->leftJoin(
                [$ClassStudents->alias() => $ClassStudents->table()],
                [
                    $ClassStudents->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $ClassStudents->aliasField('institution_id = ') . $this->aliasField('institution_id'),
                    $ClassStudents->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $ClassStudents->aliasField('student_status_id = ') . $this->aliasField('student_status_id'),
                    $ClassStudents->aliasField('academic_period_id = ') . $this->aliasField('academic_period_id'),
                ]
            )
            ->leftJoin(
                [$Classes->alias() => $Classes->table()],
                [
                    $Classes->aliasField('id = ') . $ClassStudents->aliasField('institution_class_id'),
                ]
            );

        if ($periodId > 0) {
            $query->where([$this->aliasField('academic_period_id') => $periodId]);
        }
    }

    public function onExcelUpdateFields(Event $event, ArrayObject $settings, ArrayObject $fields)
    {
        $IdentityType = TableRegistry::get('FieldOption.IdentityTypes');
        $identity = $IdentityType->getDefaultEntity();

        $extraField[] = [
            'key' => 'Institutions.code',
            'field' => 'code',
            'type' => 'string',
            'label' => '',
        ];

        $extraField[] = [
            'key' => 'Institutions.name',
            'field' => 'institution_name',
            'type' => 'string',
            'label' => __('Institution'),
        ];

        $extraField[] = [
            'key' => 'Users.openemis_no',
            'field' => 'openemis_no',
            'type' => 'string',
            'label' => '',
        ];

        $extraField[] = [
            'key' => 'Users.gender_id',
            'field' => 'gender_name',
            'type' => 'string',
            'label' => '',
        ];

        $extraField[] = [
            'key' => 'Users.date_of_birth',
            'field' => 'date_of_birth',
            'type' => 'date',
            'label' => '',
        ];

        $extraField[] = [
            'key' => 'Users.identity_number',
            'field' => 'identity_number',
            'type' => 'string',
            'label' => __($identity->name),
        ];

        $extraField[] = [
            'key' => 'MainNationalities.name',
            'field' => 'nationalities',
            'type' => 'string',
            'label' => __('Nationalities'),
        ];

        $extraField[] = [
            'key' => 'StudentStatuses.name',
            'field' => 'student_status',
            'type' => 'string',
            'label' => __('Student Status'),
        ];

        $extraField[] = [
            'key' => 'Users.student_name',
            'field' => 'student_name',
            'type' => 'string',
            'label' => __('Student'),
        ];

        $extraField[] = [
            'key' => 'EducationGrades.name',
            'field' => 'education_grade',
            'type' => 'string',
            'label' => __('Education Grades'),
        ];

        $extraField[] = [
            'key' => 'InstitutionClasses.name',
            'field' => 'class_name',
            'type' => 'string',
            'label' => __('Class'),
        ];

        $extraField[] = [
            'key' => 'AcademicPeriods.name',
            'field' => 'academic_period',
            'type' => 'string',
            'label' => __('Academic Period'),
        ];

        $extraField[] = [
            'key' => 'InstitutionStudents.start_date',
            'field' => 'start_date',
            'type' => 'date',
            'label' => '',
        ];

        $extraField[] = [
            'key' => 'InstitutionStudents.end_date',
            'field' => 'end_date',
            'type' => 'date',
            'label' => '',
        ];

        $fields->exchangeArray($extraField);
    }

    // public function onExcelRenderIdentities(Event $event, Entity $entity, array $attr) {
    //     $str = '';
    //     if(!empty($entity['user']['identities'])) {
    //         $identities = $entity['user']['identities'];
    //         foreach ($identities as $identity) {
    //             $number = $identity['number'];
    //             $identityType = $identity['identity_type']['name'];
    //             $str .= '('.$identityType.') '.$number.', ';
    //         }
    //     }
    //     if (!empty($str)) {
    //         $str = substr($str, 0, -2);
    //     }
    //     return $str;
    // }

    public function onExcelRenderNationalities(Event $event, Entity $entity, array $attr)
    {
        $str = '';
        if (!empty($entity['user']['nationalities'])) {
            $nationalities = $entity['user']['nationalities'];
            foreach ($nationalities as $nationality) {
                if (isset($nationality['nationalities_look_up']['name'])) {
                    $str .= $nationality['nationalities_look_up']['name'] . ', ';
                }
            }
        }
        if (!empty($str)) {
            $str = substr($str, 0, -2);
        }
        return $str;
    }

    public function onExcelGetStudentName(Event $event, Entity $entity)
    {
        $studentName = [];
        ($entity->student_first_name) ? $studentName[] = $entity->student_first_name : '';
        ($entity->student_middle_name) ? $studentName[] = $entity->student_middle_name : '';
        ($entity->student_third_name) ? $studentName[] = $entity->student_third_name : '';
        ($entity->student_last_name) ? $studentName[] = $entity->student_last_name : '';

        return implode(' ', $studentName);
    }

    // returns error message if validation false
    public function validateEnrolledInAnyInstitution($studentId, $systemId, $options = [])
    {
        $newOptions['getInstitutions'] = true;
        $options = array_merge($options, $newOptions);

        // targetInstitutionId is used to determine the error message, whether it is enrolled in 'this' or 'other' institution
        $targetInstitutionId = (array_key_exists('targetInstitutionId', $options)) ? $options['targetInstitutionId'] : null;

        $enrolledInstitutionIds = $this->enrolledInAnyInstitution($studentId, $systemId, $options);

        if (is_array($enrolledInstitutionIds) && !empty($enrolledInstitutionIds)) {
            if (!empty($targetInstitutionId) && in_array($targetInstitutionId, $enrolledInstitutionIds)) {
                // 'Student is already enrolled in target school.'
                return $this->getMessage('Institution.Students.student_name.ruleStudentNotEnrolledInAnyInstitutionAndSameEducationSystem.inTargetSchool');
            } else {
                // 'Student is already enrolled in another school.'
                return $this->getMessage('Institution.Students.student_name.ruleStudentNotEnrolledInAnyInstitutionAndSameEducationSystem.inAnotherSchool');
            }
        } else {
            return false;
        }
    }

    private function enrolledInAnyInstitution($studentId, $systemId, $options = [])
    {
        $newOptions['select'] = ['institution_id', 'education_grade_id'];
        $options = array_merge($options, $newOptions);
        $getInstitutions = (array_key_exists('getInstitutions', $options)) ? $options['getInstitutions'] : false;

        $EducationGradesTable = TableRegistry::get('Education.EducationGrades');

        $options['studentId'] = $studentId;
        $enrolledRecords = $this->find('byStatus', $options)->toArray();

        $existingRecordsInSameSystem = [];
        foreach ($enrolledRecords as $key => $value) {
            $enrolledRecords[$key]->education_system_id = $EducationGradesTable->getEducationSystemId($value->education_grade_id);
            if ($value->education_system_id == $systemId) {
                $existingRecordsInSameSystem[] = $value;
            }
        }

        // returns a true/false if !getInstitutions else returns an array of institution_ids
        if (!$getInstitutions) {
            return !empty($existingRecordsInSameSystem);
        } else {
            $institutionIds = [];
            foreach ($existingRecordsInSameSystem as $key => $value) {
                $institutionIds[$value->institution_id] = $value->institution_id;
            }
            return $institutionIds;
        }
    }

    public function findByStatus(Query $query, array $options)
    {
        $studentId = $options['studentId'];
        $statusCode = 'CURRENT';
        if (array_key_exists('code', $options)) {
            $statusCode = $options['code'];
        }
        $status = $this->StudentStatuses->getIdByCode($statusCode);

        $conditions = [
            $this->aliasField('student_id') => $studentId,
            $this->aliasField('student_status_id') => $status,
        ];

        if (array_key_exists('excludeInstitutions', $options) && !empty($options['excludeInstitutions'])) {
            $conditions[$this->aliasField('institution_id') . ' NOT IN '] = $options['excludeInstitutions'];
        }

        if (array_key_exists('select', $options) && !empty($options['select'])) {
            $query->select($options['select']);
        }

        $query->where($conditions);
        return $query;
    }

    public function findWithClass(Query $query, array $options)
    {
        $institutionId = $options['institution_id'];
        $periodId = $options['period_id'];

        $ClassStudents = TableRegistry::get('Institution.InstitutionClassStudents');
        $Classes = TableRegistry::get('Institution.InstitutionClasses');

        return $query
            ->select([$Classes->aliasField('name')])
            ->leftJoin(
                [$ClassStudents->alias() => $ClassStudents->table()],
                [
                    $ClassStudents->aliasField('student_id = ') . $this->aliasField('student_id'),
                    $ClassStudents->aliasField('education_grade_id = ') . $this->aliasField('education_grade_id'),
                    $ClassStudents->aliasField('student_status_id = ') . $this->aliasField('student_status_id'),
                ]
            )
            ->leftJoin(
                [$Classes->alias() => $Classes->table()],
                [
                    $Classes->aliasField('id = ') . $ClassStudents->aliasField('institution_class_id'),
                    $Classes->aliasField('academic_period_id') => $periodId,
                    $Classes->aliasField('institution_id') => $institutionId,
                ]
            );
    }

    public function findTripPassengers(Query $query, array $options)
    {
        $queryString = array_key_exists('querystring', $options) ? $options['querystring'] : [];
        $institutionId = array_key_exists('institution_id', $queryString) ? $queryString['institution_id'] : 0;
        $academicPeriodId = array_key_exists('academic_period_id', $queryString) ? $queryString['academic_period_id'] : 0;

        $query
            ->select([
                $this->aliasField('id'),
                $this->Users->aliasField('openemis_no'),
                $this->Users->aliasField('first_name'),
                $this->Users->aliasField('middle_name'),
                $this->Users->aliasField('third_name'),
                $this->Users->aliasField('last_name'),
                $this->Users->aliasField('preferred_name'),
            ])
            ->contain($this->Users->alias())
            ->where([
                $this->aliasField('institution_id') => $institutionId,
                $this->aliasField('academic_period_id') => $academicPeriodId,
            ])
            ->formatResults(function (ResultSetInterface $results) {
                $returnResult = [];

                foreach ($results as $result) {
                    $returnResult[] = ['value' => $result->id, 'text' => $result->user->name_with_id];
                }

                return $returnResult;
            });

        return $query;
    }

    public function onGetAdmissionId(Event $event, Entity $entity)
    {
        return $entity->admission_id > 0 ? $entity->admission_id : 'Not Provided';
    }

    public function beforeAction(Event $event, ArrayObject $extra)
    {
        $this->field('previous_institution_student_id', ['type' => 'hidden']);
        // $this->field('admission_id', ['type' => 'string', 'attr' => ['label' => 'Admission No']]);
        $this->field('admission_id', ['attr' => ['label' => 'Admission Number']]);
    }

    public function beforeDelete(Event $event, Entity $entity)
    {
        $studentStatuses = $this->StudentStatuses->findCodeList();
        // if user tries to delete record that is not enrolled
        if ($entity->student_status_id != $studentStatuses['CURRENT']) {
            $event->stopPropagation();
            return false;
        }
    }

    public function indexBeforeAction(Event $event, ArrayObject $extra)
    {
        $session = $this->request->session();
        $institutionId = !empty($this->request->param('institutionId')) ? $this->paramsDecode($this->request->param('institutionId'))['id'] : $session->read('Institution.Institutions.id');

        $this->field('academic_period_id', ['visible' => false]);
        $this->field('class', ['after' => 'education_grade_id']);
        $this->field('student_status_id', ['after' => 'class']);
        $this->field('admission_id', ['after' => 'student_status_id']);
        $this->field('area_administrative_id', ['visible' => false]);
        $this->fields['start_date']['visible'] = false;
        $this->fields['end_date']['visible'] = false;
        $this->fields['class']['sort'] = ['field' => 'InstitutionClasses.name'];
        $this->fields['student_id']['sort'] = ['field' => 'Users.first_name'];

        $this->controller->set('ngController', 'AdvancedSearchCtrl');

        $StudentStatusesTable = $this->StudentStatuses;
        $status = $StudentStatusesTable->findCodeList();
        $selectedStatus = $this->request->query('status_id');

        // To redirect to Pending statuses page
        $pendingStatuses = [
            self::PENDING_ADMISSION => 'StudentAdmission',
            self::PENDING_TRANSFERIN => 'StudentTransferIn',
            self::PENDING_TRANSFEROUT => 'StudentTransferOut',
            self::PENDING_WITHDRAW => 'StudentWithdraw',
        ];

        if (array_key_exists($selectedStatus, $pendingStatuses)) {
            $url = ['plugin' => 'Institution', 'controller' => 'Institutions', 'institutionId' => $this->paramsEncode(['id' => $institutionId])];
            $url['action'] = $pendingStatuses[$selectedStatus];
            $event->stopPropagation();
            return $this->controller->redirect($url);
        }

        // from onUpdateToolbarButtons
        $btnAttr = [
            'class' => 'btn btn-xs btn-default icon-big',
            'data-toggle' => 'tooltip',
            'data-placement' => 'bottom',
            'escape' => false,
        ];
        $buttons = $extra['indexButtons'];

        $extraButtons = [
            'graduate' => [
                'permission' => ['Institutions', 'Promotion', 'add'],
                'action' => 'Promotion',
                'icon' => '<i class="fa kd-graduate"></i>',
                'title' => __('Promotion / Graduation'),
            ],
            'transfer' => [
                'permission' => ['Institutions', 'Transfer', 'add'],
                'action' => 'Transfer',
                'icon' => '<i class="fa kd-transfer"></i>',
                'title' => __('Transfer'),
            ],
            'undo' => [
                'permission' => ['Institutions', 'Undo', 'add'],
                'action' => 'Undo',
                'icon' => '<i class="fa kd-undo"></i>',
                'title' => __('Undo'),
            ],
        ];

        if (Configure::read('schoolMode')) {
            unset($extraButtons['transfer']);
        }

        foreach ($extraButtons as $key => $attr) {
            if ($this->AccessControl->check($attr['permission'])) {
                $button = [
                    'type' => 'button',
                    'attr' => $btnAttr,
                    'url' => [0 => 'add'],
                ];
                $button['url']['action'] = $attr['action'];
                $button['attr']['title'] = $attr['title'];
                $button['label'] = $attr['icon'];

                $extra['toolbarButtons'][$key] = $button;
            }
        }
    }

    public function indexBeforeQuery(Event $event, Query $query, ArrayObject $extra)
    {
        $request = $this->request;
        $query->contain(['EducationGrades']);

        // Student Statuses
        $statusOptions = $this->StudentStatuses
            ->find('list')
            ->toArray();
        $StudentStatusesTable = $this->StudentStatuses;
        $pendingStatus = [
            self::PENDING_TRANSFERIN => __('Pending Transfer In'),
            self::PENDING_TRANSFEROUT => __('Pending Transfer Out'),
            self::PENDING_ADMISSION => __('Pending Admission'),
            self::PENDING_WITHDRAW => __('Pending Withdraw'),
        ];

        $statusOptions = $statusOptions + $pendingStatus;

        // Academic Periods
        $academicPeriodOptions = $this->AcademicPeriods->getYearList();

        // Education Grades
        $InstitutionEducationGrades = TableRegistry::get('Institution.InstitutionGrades');
        $session = $this->Session;
        $institutionId = $session->read('Institution.Institutions.id');

        $educationGradesOptions = $InstitutionEducationGrades
            ->find('list', [
                'keyField' => 'EducationGrades.id',
                'valueField' => 'EducationGrades.name',
            ])
            ->select([
                'EducationGrades.id', 'EducationGrades.name',
            ])
            ->contain(['EducationGrades'])
            ->where(['institution_id' => $institutionId])
            ->group('education_grade_id')
            ->toArray();

        $educationGradesOptions = ['-1' => __('All Grades')] + $educationGradesOptions;

        // Query Strings

        if (empty($request->query['academic_period_id'])) {
            $request->query['academic_period_id'] = $this->AcademicPeriods->getCurrent();
        }
        $selectedStatus = $this->queryString('status_id', $statusOptions);
        $selectedEducationGrades = $this->queryString('education_grade_id', $educationGradesOptions);
        $selectedAcademicPeriod = $this->queryString('academic_period_id', $academicPeriodOptions);

        // Advanced Select Options
        $this->advancedSelectOptions($statusOptions, $selectedStatus);
        $studentTable = $this;
        $this->advancedSelectOptions($academicPeriodOptions, $selectedAcademicPeriod, [
            'message' => '{{label}} - ' . $this->getMessage($this->aliasField('noStudents')),
            'callable' => function ($id) use ($studentTable, $institutionId) {
                return $studentTable->find()->where(['institution_id' => $institutionId, 'academic_period_id' => $id])->count();
            },
        ]);

        $request->query['academic_period_id'] = $selectedAcademicPeriod;

        // To add the academic_period_id to export
        if (isset($extra['toolbarButtons']['export']['url'])) {
            $extra['toolbarButtons']['export']['url']['academic_period_id'] = $selectedAcademicPeriod;
        }

        $this->advancedSelectOptions($educationGradesOptions, $selectedEducationGrades);

        if ($selectedEducationGrades != -1) {
            $query->where([$this->aliasField('education_grade_id') => $selectedEducationGrades]);
        }

        $staffId = $this->Auth->user('id');
        $query->where([$this->aliasField('academic_period_id') => $selectedAcademicPeriod]);

        //$query->where([$this->aliasField('staff_id') => $staffId]);
        // Start: sort by class column
        $session = $request->session();
        $institutionId = $session->read('Institution.Institutions.id');

        $query->find('withClass', ['institution_id' => $institutionId, 'period_id' => $selectedAcademicPeriod]);

        $sortList = ['InstitutionClasses.name'];
        if (array_key_exists('sortWhitelist', $extra['options'])) {
            $sortList = array_merge($extra['options']['sortWhitelist'], $sortList);
        }
        $extra['options']['sortWhitelist'] = $sortList;
        // End

        $search = $this->getSearchKey();
        if (!empty($search)) {
            // function from AdvancedNameSearchBehavior
            $query = $this->addSearchConditions($query, ['alias' => 'Users', 'searchTerm' => $search]);
        } else {
            if (!$this->isAdvancedSearchEnabled() && $selectedStatus != -1) {
                $query->where([$this->aliasField('student_status_id') => $selectedStatus]);
            }
        }

        //select specific field that is used on the page, photo_content is generated by LazyEagerLoader (javascript)
        //the rest of fields are called by onGet function.
        $query->select([
            $this->aliasField('id'),
            'Users.id',
            'Users.openemis_no',
            'Users.first_name',
            'Users.middle_name',
            'Users.third_name',
            'Users.last_name',
            'Users.preferred_name',
            'student_status_id',
            'admission_id',
        ]);

        // POCOR-2869 implemented to hide the retrieval of records from another school resulting in duplication - proper fix will be done in SOJOR-437
        $query->group([$this->aliasField('student_id'), $this->aliasField('academic_period_id'), $this->aliasField('institution_id'), $this->aliasField('education_grade_id'), $this->aliasField('student_status_id')]);

        // POCOR-2547 sort list of staff and student by name
        if (!isset($request->query['sort'])) {
            $query->order([$this->Users->aliasField('first_name')]);
        }

        $this->controller->set(compact('statusOptions', 'academicPeriodOptions', 'educationGradesOptions'));
    }

    public function indexAfterAction(Event $event, Query $query, ResultSet $resultSet, ArrayObject $extra)
    {
        $this->dashboardQuery = clone $query;
    }

    public function viewBeforeAction(Event $event, ArrayObject $extra)
    {
        $this->field('photo_content', ['type' => 'image', 'before' => 'openemis_no']);
        $this->field('openemis_no', ['type' => 'readonly', 'order' => 1]);
        $this->fields['student_id']['order'] = 10;
        $extra['toolbarButtons']['back']['url']['action'] = 'StudentProgrammes';
    }

    public function viewAfterAction(Event $event, Entity $entity, ArrayObject $extra)
    {
        $studentStatusId = $entity->student_status_id;
        $statuses = $this->StudentStatuses->findCodeList();
        $code = array_search($studentStatusId, $statuses);

        if ($code == 'WITHDRAWN' || $code == 'TRANSFERRED') {
            $this->field('reason', ['type' => 'custom_status_reason']);
            $this->field('comment');
            $this->setFieldOrder([
                'photo_content', 'openemis_no', 'student_id', 'student_status_id', 'reason', 'comment',
            ]);
        } elseif ($code != 'CURRENT') { // only enrolled students can be edited or removed
            $this->toggle('remove', false);
            $this->toggle('edit', false);
        }

        $this->Session->write('Student.Students.id', $entity->student_id);
        $this->Session->write('Student.Students.name', $entity->user->name);
        $this->setupTabElements($entity);
    }

    public function editBeforeQuery(Event $event, Query $query)
    {
        $query->contain(['Users', 'EducationGrades', 'AcademicPeriods', 'StudentStatuses']);
    }

    public function editAfterAction(Event $event, Entity $entity)
    {
        // Start PHPOE-1897
        $statuses = $this->StudentStatuses->findCodeList();
        if ($entity->student_status_id != $statuses['CURRENT']) {
            $event->stopPropagation();
            $urlParams = $this->url('view');
            return $this->controller->redirect($urlParams);
            // End PHPOE-1897
        } else {
            $this->field('student_id', [
                'type' => 'readonly',
                'order' => 10,
                'attr' => ['value' => $entity->user->name_with_id],
            ]);

            $this->field('education_grade_id', ['type' => 'readonly', 'attr' => ['value' => $entity->education_grade->programme_grade_name]]);
            $this->field('academic_period_id', ['type' => 'readonly', 'attr' => ['value' => $entity->academic_period->name]]);
            $this->field('student_status_id', ['type' => 'readonly', 'attr' => ['value' => $entity->student_status->name]]);

            $period = $entity->academic_period;
            $dateOptions = [
                'startDate' => $period->start_date->format('d-m-Y'),
                'endDate' => $period->end_date->format('d-m-Y'),
            ];

            $this->fields['start_date']['date_options'] = $dateOptions;
            $this->fields['end_date']['date_options'] = $dateOptions;

            $this->Session->write('Student.Students.id', $entity->student_id);
            $this->Session->write('Student.Students.name', $entity->user->name);
            $this->setupTabElements($entity);
        }
    }

    public function afterAction(Event $event, ArrayObject $extra)
    {
        if ($this->action == 'index') {
            $InstitutionArray = [];
            $institutionStudentQuery = clone $this->dashboardQuery;
            $studentCount = $institutionStudentQuery->group([$this->aliasField('student_id')])->count();
            unset($institutionStudentQuery);

            //Get Gender
            $InstitutionArray[__('Gender')] = $this->getDonutChart(
                'institution_student_gender',
                ['query' => $this->dashboardQuery, 'key' => __('Gender')]
            );

            // Get Age
            $InstitutionArray[__('Age')] = $this->getDonutChart(
                'institution_student_age',
                ['query' => $this->dashboardQuery, 'key' => __('Age')]
            );

            // Get Grades
            $InstitutionArray[__('Grade')] = $this->getDonutChart(
                'institution_class_student_grade',
                ['query' => $this->dashboardQuery, 'key' => __('Grade')]
            );

            $indexDashboard = 'dashboard';

            $indexElements = (isset($this->controller->viewVars['indexElements'])) ? $this->controller->viewVars['indexElements'] : [];

            $indexElements[] = ['name' => 'Institution.Students/controls', 'data' => [], 'options' => [], 'order' => 0];

            if (!$this->isAdvancedSearchEnabled()) { //function to determine whether dashboard should be shown or not
                $indexElements[] = [
                    'name' => $indexDashboard,
                    'data' => [
                        'model' => 'students',
                        'modelCount' => $studentCount,
                        'modelArray' => $InstitutionArray,
                    ],
                    'options' => [],
                    'order' => 2,
                ];
            }

            foreach ($indexElements as $key => $value) {
                if ($value['name'] == 'OpenEmis.ControllerAction/index') {
                    $indexElements[$key]['order'] = 3;
                } elseif ($value['name'] == 'OpenEmis.pagination') {
                    $indexElements[$key]['order'] = 4;
                }
            }

            $extra['elements'] = array_merge($extra['elements'], $indexElements);
        }
    }

    public function afterSave(Event $event, Entity $entity, ArrayObject $options)
    {
        $listeners = [
            TableRegistry::get('Institution.StudentAdmission'),
            TableRegistry::get('Institution.StudentTransferIn'),
            TableRegistry::get('Institution.StudentTransferOut'),
            TableRegistry::get('Institution.InstitutionClassStudents'),
            TableRegistry::get('Institution.InstitutionSubjectStudents'),
            TableRegistry::get('Institution.StudentUser'),
            $this->Users,
        ];
        $this->dispatchEventToModels('Model.Students.afterSave', [$entity], $this, $listeners);

        //if new record has no previous_institution_student_id value yet, then try to update it.
        if (!$entity->has('previous_institution_student_id')) {
            $prevInstitutionStudent = $this
                ->find()
                ->where([
                    $this->aliasField('student_id') => $entity->student_id,
                    $this->aliasField('id <> ') => $entity->id,
                ])
                ->order([
                    'created' => 'desc',
                    'start_date' => 'desc',
                ])
                ->first();

            if ($prevInstitutionStudent) { //if has previous record.
                $this->updateAll(
                    ['previous_institution_student_id' => $prevInstitutionStudent->id],
                    ['id' => $entity->id]
                );
            }
        }
    }

    public function onGetStudentId(Event $event, Entity $entity)
    {
        $value = '';
        if ($entity->has('user')) {
            $value = $entity->user->name;
        } else {
            $value = $entity->_matchingData['Users']->name;
        }
        return $value;
    }

    public function onGetEducationGradeId(Event $event, Entity $entity)
    {
        $value = '';
        if ($entity->has('education_grade')) {
            $value = $entity->education_grade->programme_grade_name;
        }
        return $value;
    }

    public function onGetClass(Event $event, Entity $entity)
    {
        $value = '';

        if ($entity->has('InstitutionClasses')) {
            $value = $entity->InstitutionClasses['name'];
        }

        return $value;
    }

    public function onGetCustomStatusReasonElement(Event $event, $action, $entity, $attr, $options = [])
    {
        if ($this->action == 'view') {
            $studentStatusId = $entity->student_status_id;
            $statuses = $this->StudentStatuses->findCodeList();
            $code = array_search($studentStatusId, $statuses);
            $institutionId = $entity->institution_id;
            $educationGradeId = $entity->education_grade_id;
            $studentId = $entity->getOriginal('student_id'); // student_id is changed in onGetStudentId
            $academicPeriodId = $entity->academic_period_id;

            switch ($code) {
                case 'TRANSFERRED':
                    $StudentTransfersTable = TableRegistry::get('Institution.InstitutionStudentTransfers');
                    $approvedStatuses = $StudentTransfersTable->getStudentTransferWorkflowStatuses('APPROVED');

                    $transferReason = $StudentTransfersTable->find()
                        ->matching('StudentTransferReasons')
                        ->where([
                            $StudentTransfersTable->aliasField('student_id') => $studentId,
                            $StudentTransfersTable->aliasField('previous_institution_id') => $institutionId,
                            $StudentTransfersTable->aliasField('previous_education_grade_id') => $educationGradeId,
                            $StudentTransfersTable->aliasField('previous_academic_period_id') => $academicPeriodId,
                            $StudentTransfersTable->aliasField('status_id IN ') => $approvedStatuses,
                        ])
                        ->first();

                    $entity->comment = $transferReason->comment;

                    return $transferReason->_matchingData['StudentTransferReasons']->name;
                    break;

                case 'WITHDRAWN':
                    $WithdrawRequestsTable = TableRegistry::get('Institution.WithdrawRequests');
                    $WorkflowModelsTable = TableRegistry::get('Workflow.WorkflowModels');
                    $approvedStatus = $WorkflowModelsTable->getWorkflowStatusSteps('Institution.StudentWithdraw', 'APPROVED');

                    $withdrawReason = $WithdrawRequestsTable->find()
                        ->matching('StudentWithdrawReasons')
                        ->where([
                            $WithdrawRequestsTable->aliasField('student_id') => $studentId,
                            $WithdrawRequestsTable->aliasField('academic_period_id') => $academicPeriodId,
                            $WithdrawRequestsTable->aliasField('institution_id') => $institutionId,
                            $WithdrawRequestsTable->aliasField('education_grade_id') => $educationGradeId,
                            $WithdrawRequestsTable->aliasField('status_id') . ' IN ' => $approvedStatus,
                        ])
                        ->first();

                    $comment = '';
                    $studentWithdrawReason = '';
                    if (!empty($withdrawReason)) {
                        $comment = $withdrawReason->comment;
                        $studentWithdrawReason = $withdrawReason->_matchingData['StudentWithdrawReasons']->name;
                    }

                    $entity->comment = $comment;
                    return $studentWithdrawReason;
                    break;
            }
        }
    }

    public function onGetComment(Event $event, Entity $entity)
    {
        if ($this->action == 'view') {
            return nl2br($entity->comment);
        }
    }

    // Start PHPOE-1897
    public function onUpdateActionButtons(Event $event, Entity $entity, array $buttons)
    {
        $buttons = parent::onUpdateActionButtons($event, $entity, $buttons);
        if (isset($buttons['view'])) {
            $url = $this->url('view');
            $userId = $this->paramsEncode(['id' => $entity->_matchingData['Users']->id]);
            $buttons['view']['url'] = array_merge($url, ['action' => 'StudentUser', $userId]);
            $buttons['view']['url'] = $this->setQueryString($buttons['view']['url'], ['institution_student_id' => $entity->id]);

            // POCOR-3125 history button permission to hide and show the link
            if ($this->AccessControl->check(['StudentHistories', 'index'])) {
                $institutionId = $this->paramsEncode(['id' => $entity->institution->id]);

                $icon = '<i class="fa fa-history"></i>';
                $url = [
                    'plugin' => 'Institution',
                    'institutionId' => $institutionId,
                    'controller' => 'StudentHistories',
                    'action' => 'index',
                ];

                $buttons['history'] = $buttons['view'];
                $buttons['history']['label'] = $icon . __('History');
                $buttons['history']['url'] = $this->ControllerAction->setQueryString($url, [
                    'security_user_id' => $entity->_matchingData['Users']->id,
                    'user_type' => 'Student',
                ]);
            }
            // end POCOR-3125 history button permission
        }

        // Remove in POCOR-3010
        if (isset($buttons['edit'])) {
            unset($buttons['edit']);
        }

        // if student is not currently enrolled in this institution, remove the delete button
        $studentStatuses = $this->StudentStatuses->findCodeList();
        if ($entity->student_status_id != $studentStatuses['CURRENT']) {
            if (isset($buttons['remove'])) {
                unset($buttons['remove']);
            }
        }
        return $buttons;
    }
    // End PHPOE-1897

    private function setupTabElements($entity)
    {
        $options['type'] = 'student';
        $tabElements = TableRegistry::get('Institution.StudentUser')->getAcademicTabElements($options);
        $tabElements = $this->controller->TabPermission->checkTabPermission($tabElements);
        $this->controller->set('tabElements', $tabElements);
        $this->controller->set('selectedAction', 'Programmes');
    }

    public function checkEnrolledInInstitution($studentId, $institutionId)
    {
        $statuses = TableRegistry::get('Student.StudentStatuses')->findCodeList();
        $status = $this
            ->find()
            ->where([$this->aliasField('student_id') => $studentId,
                $this->aliasField('institution_id') => $institutionId,
                $this->aliasField('student_status_id') => $statuses['CURRENT'],
            ])
            ->count();
        return $status > 0;
    }

    public function checkIfCanTransfer($student, $institutionId)
    {
        $gradeId = ($student->has('education_grade_id')) ? $student->education_grade_id : null;
        $studentId = ($student->has('student_id')) ? $student->student_id : null;
        if (empty($gradeId) || empty($studentId)) {
            // missing critical parameter - grade, student_id - cant transfer
            return false;
        }

        // check if student exists in current year
        $academicPeriodId = ($student->has('academic_period_id')) ? $student->academic_period_id : null;
        $currentAcademicPeriod = $this->AcademicPeriods->getCurrent();
        if ($academicPeriodId != $currentAcademicPeriod) {
            return false;
        }

        $StudentStatuses = TableRegistry::get('Student.StudentStatuses');
        $studentStatusList = array_flip($StudentStatuses->findCodeList());

        $checkIfCanTransfer = (in_array($studentStatusList[$student->student_status_id], ['CURRENT', 'PROMOTED', 'GRADUATED']));

        // check ruleStudentNotEnrolledInAnyInstitutionAndSameEducationSystem && ruleStudentNotCompletedGrade
        $newSystemId = TableRegistry::get('Education.EducationGrades')->getEducationSystemId($gradeId);
        $validateEnrolledInAnyInstitutionResult = $this->validateEnrolledInAnyInstitution($studentId, $newSystemId, ['excludeInstitutions' => $institutionId]);

        if ($checkIfCanTransfer) {
            if (!empty($validateEnrolledInAnyInstitutionResult) ||
                $this->completedGrade($gradeId, $studentId)) {
                $checkIfCanTransfer = false;
            }
        }

        // additional logic for PROMOTED
        if ($checkIfCanTransfer && $studentStatusList[$student->student_status_id] == 'PROMOTED') {
            //'Promoted' status - this feature will be available if the student is at the last grade that the school offers
            // Education Grades
            $InstitutionEducationGrades = TableRegistry::get('Institution.InstitutionGrades');
            $EducationGrades = TableRegistry::get('Education.EducationGrades');

            $studentEducationGrade = $EducationGrades
                ->find()
                ->where([$EducationGrades->aliasField($EducationGrades->primaryKey()) => $gradeId])
                ->first();

            $currentProgrammeGrades = $EducationGrades
                ->find('list', [
                    'keyField' => 'id',

                    'valueField' => 'programme_grade_name',
                ])
                ->find('visible')
                ->where([
                    $this->EducationGrades->aliasField('order') . ' > ' => $studentEducationGrade->order,
                    $this->EducationGrades->aliasField('education_programme_id') => $studentEducationGrade->education_programme_id,
                ])
                ->toArray();

            $EducationProgrammesNextProgrammesTable = TableRegistry::get('Education.EducationProgrammesNextProgrammes');
            $educationProgrammeId = $studentEducationGrade->education_programme_id;
            $nextEducationGradeList = $EducationProgrammesNextProgrammesTable->getNextGradeList($educationProgrammeId);
            $moreAdvancedEducationGrades = $currentProgrammeGrades + $nextEducationGradeList;

            $studentEducationGradeOrder = [];
            if (!empty($studentEducationGrade)) {
                $studentEducationGradeOrder = $studentEducationGrade->order;
            }

            $advancedGradeOptionsLeft = $InstitutionEducationGrades
                ->find('list', [
                    'keyField' => 'EducationGrades.id',
                    'valueField' => 'EducationGrades.name',
                ])
                ->select([
                    'EducationGrades.id', 'EducationGrades.name', 'EducationGrades.order',
                ])
                ->contain(['EducationGrades'])
                ->where(['EducationGrades.order > ' => $studentEducationGradeOrder])
                ->where(['institution_id' => $institutionId])
                ->group('education_grade_id')
                ->toArray()
            ;

            // if there are more advanced grades available to the student, the student cannot transfer
            if (count(array_intersect_key($moreAdvancedEducationGrades, $advancedGradeOptionsLeft)) > 0) {
                $checkIfCanTransfer = false;
            }
        }
        return $checkIfCanTransfer;
    }

    // Function use by the mini dashboard (For Institution Students)
    public function getNumberOfStudentsByGender($params = [])
    {
        $query = $params['query'];
        $InstitutionRecords = clone $query;
        $InstitutionStudentCount = $InstitutionRecords
            ->matching('Users.Genders')
            ->select([
                'count' => $InstitutionRecords->func()->count('DISTINCT ' . $this->aliasField('student_id')),
                'gender' => 'Genders.name',
            ])
            ->group(['gender'], true);

        // Creating the data set
        $dataSet = [];
        foreach ($InstitutionStudentCount->toArray() as $value) {
            //Compile the dataset
            $dataSet[] = [__($value['gender']), $value['count']];
        }
        $params['dataSet'] = $dataSet;
        unset($InstitutionRecords);
        return $params;
    }

    // Function use by the mini dashboard (For Institution Students)
    public function getNumberOfStudentsByAge($params = [])
    {
        $query = $params['query'];
        $InstitutionRecords = $query->cleanCopy();
        $ageQuery = $InstitutionRecords
            ->select([
                'age' => $InstitutionRecords->func()->dateDiff([
                    $InstitutionRecords->func()->now(),
                    'Users.date_of_birth' => 'literal',
                ]),
                'student' => $this->aliasField('student_id'),
            ])
            ->distinct(['student'])
            ->order('age');

        $InstitutionStudentCount = $ageQuery->toArray();

        $convertAge = [];

        // (Logic to be reviewed)
        // Calculate the age taking account to the average of leap years
        foreach ($InstitutionStudentCount as $val) {
            $convertAge[] = floor($val['age'] / 365.25);
        }
        // Count and sort the age
        $result = [];
        $prevValue = ['age' => -1, 'count' => null];
        foreach ($convertAge as $val) {
            if ($prevValue['age'] != $val) {
                unset($prevValue);
                $prevValue = ['age' => $val, 'count' => 0];
                $result[] = &$prevValue;
            }
            $prevValue['count']++;
        }

        // Creating the data set
        $dataSet = [];
        foreach ($result as $value) {
            //Compile the dataset
            $dataSet[] = [__('Age') . ' ' . $value['age'], $value['count']];
        }
        $params['dataSet'] = $dataSet;
        unset($InstitutionRecords);
        return $params;
    }

    // Function use by the mini dashboard (For Institution Students)
    public function getNumberOfStudentsByGradeByInstitution($params = [])
    {
        $query = $params['query'];
        $InstitutionRecords = clone $query;
        $studentByGrades = $InstitutionRecords
            ->select([
                'grade' => 'EducationGrades.name',
                'count' => $query->func()->count('DISTINCT ' . $this->aliasField('student_id')),
            ])
            ->contain([
                'EducationGrades',
            ])
            ->group([$this->aliasField('education_grade_id')], true)
            ->toArray();

        $dataSet = [];
        foreach ($studentByGrades as $value) {
            $dataSet[] = [__($value['grade']), $value['count']];
        }
        $params['dataSet'] = $dataSet;
        unset($InstitutionRecords);
        return $params;
    }

    // For Dashboard (Institution Dashboard and Home Page)
    public function getNumberOfStudentsByYear($params = [])
    {
        $conditions = isset($params['conditions']) ? $params['conditions'] : [];
        $_conditions = [];
        foreach ($conditions as $key => $value) {
            $_conditions[$this->alias() . '.' . $key] = $value;
        }

        $AcademicPeriod = $this->AcademicPeriods;
        $currentPeriodId = $AcademicPeriod->getCurrent();

        $genderOptions = $this->Users->Genders->getList();
        $dataSet = new ArrayObject();
        foreach ($genderOptions as $key => $value) {
            $dataSet[$value] = ['name' => __($value), 'data' => []];
        }
        $dataSet['Total'] = ['name' => __('Total'), 'data' => []];

        $academicPeriodList = [];
        $found = false;
        foreach ($AcademicPeriod->getYearList() as $periodId => $periodName) {
            if ($found) {
                $academicPeriodList[$periodId] = $periodName;
                break;
            }
            if ($periodId == $currentPeriodId) {
                $academicPeriodList[$periodId] = $periodName;
                $found = true;
            } else {
                $academicPeriodList = [$periodId => $periodName];
            }
        }
        $academicPeriodList = array_reverse($academicPeriodList, true);

        foreach ($academicPeriodList as $periodId => $periodName) {
            foreach ($dataSet as $dkey => $dvalue) {
                if (!array_key_exists($periodName, $dataSet[$dkey]['data'])) {
                    $dataSet[$dkey]['data'][$periodName] = 0;
                }
            }

            foreach ($genderOptions as $genderId => $genderName) {
                $queryCondition = array_merge(['Genders.id' => $genderId, 'AcademicPeriods.id' => $periodId], $_conditions);

                $studentsByYear = $this
                    ->find('list', [
                        'groupField' => 'gender_name',
                        'keyField' => 'period_name',
                        'valueField' => 'total',
                    ])
                    ->matching('Users.Genders')
                    ->matching('AcademicPeriods')
                    ->select([
                        'gender_name' => 'Genders.name',
                        'period_name' => 'AcademicPeriods.name',
                        'total' => $this->find()->func()->count('DISTINCT ' . $this->aliasField('student_id')),
                    ])
                    ->where($queryCondition)
                    ->group(['gender_name', $this->aliasField('academic_period_id')])
                    ->order('AcademicPeriods.order DESC')
                    ->hydrate(false)
                    ->toArray()
                ;

                if (!empty($studentsByYear)) {
                    $dataSet[$genderName]['data'][$periodName] = $studentsByYear[$genderName][$periodName];
                    $dataSet['Total']['data'][$periodName] += $studentsByYear[$genderName][$periodName];
                }
            }
        }
        $params['dataSet'] = $dataSet->getArrayCopy();

        return $params;
    }

    // For Dashboard (Home Page and Institution Dashboard page)
    public function getNumberOfStudentsByStage($params = [])
    {
        $conditions = isset($params['conditions']) ? $params['conditions'] : [];
        $_conditions = [];
        foreach ($conditions as $key => $value) {
            $_conditions[$this->alias() . '.' . $key] = $value;
        }

        $AcademicPeriod = $this->AcademicPeriods;
        $currentYearId = $AcademicPeriod->getCurrent();

        if (!empty($currentYearId)) {
            $currentYear = $AcademicPeriod->get($currentYearId, ['fields' => 'name'])->name;
        } else {
            $currentYear = __('Not Defined');
        }

        $studentsByGradeConditions = [
            $this->aliasField('academic_period_id') => $currentYearId,
            $this->aliasField('education_grade_id') . ' IS NOT NULL',
            'Genders.name IS NOT NULL',
        ];
        $studentsByGradeConditions = array_merge($studentsByGradeConditions, $_conditions);
        $query = $this->find();
        $studentByGrades = $query
            ->select([
                $this->aliasField('institution_id'),
                $this->aliasField('education_grade_id'),
                'EducationGrades.name',
                'EducationGrades.education_stage_id',
                'EducationStages.name',
                'EducationStages.order',
                'Users.id',
                'Genders.name',
                'total' => $query->func()->count($this->aliasField('id')),
            ])
            ->contain([
                'EducationGrades.EducationStages',
                'EducationGrades.EducationProgrammes.EducationCycles.EducationLevels',
                'Users.Genders',
            ])
            ->where($studentsByGradeConditions)
            ->group([
                'EducationGrades.education_stage_id',
                'Genders.name',
            ])
            ->order(
                ['EducationLevels.order', 'EducationCycles.order', 'EducationProgrammes.order', 'EducationStages.order']
            )
            ->toArray()
        ;

        $grades = [];

        $genderOptions = $this->Users->Genders->getList();
        $dataSet = array();
        foreach ($genderOptions as $key => $value) {
            $dataSet[$value] = array('name' => __($value), 'data' => array());
        }
        $dataSet['Total'] = ['name' => __('Total'), 'data' => []];

        foreach ($studentByGrades as $key => $studentByGrade) {
            $gradeId = $studentByGrade->education_grade->education_stage_id;
            $gradeName = $studentByGrade->education_grade->education_stage->name;
            $gradeGender = $studentByGrade->user->gender->name;
            $gradeTotal = $studentByGrade->total;

            $grades[$gradeId] = $gradeName;

            foreach ($dataSet as $dkey => $dvalue) {
                if (!array_key_exists($gradeId, $dataSet[$dkey]['data'])) {
                    $dataSet[$dkey]['data'][$gradeId] = 0;
                }
            }
            $dataSet[$gradeGender]['data'][$gradeId] = $gradeTotal;
            $dataSet['Total']['data'][$gradeId] += $gradeTotal;
        }

        // $params['options']['subtitle'] = array('text' => 'For Year '. $currentYear);
        $params['options']['subtitle'] = array('text' => sprintf(__('For Year %s'), $currentYear));
        $params['options']['xAxis']['categories'] = array_values($grades);
        $params['dataSet'] = $dataSet;

        return $params;
    }

    public function completedGrade($educationGradeId, $studentId)
    {
        $StudentStatuses = TableRegistry::get('Student.StudentStatuses');

        $statuses = $StudentStatuses->findCodeList();
        $completedGradeCount = $this->find()
            ->where([
                $this->aliasField('education_grade_id') => $educationGradeId,
                $this->aliasField('student_id') => $studentId,
                $this->aliasField('student_status_id') . ' IN ' => [$statuses['GRADUATED'], $statuses['PROMOTED']],
            ])
            ->count()
        ;

        return !($completedGradeCount == 0);
    }

    public function institutionStudentRiskCalculateRiskValue(Event $event, ArrayObject $params)
    {
        $institutionId = $params['institution_id'];
        $studentId = $params['student_id'];
        $academicPeriodId = $params['academic_period_id'];
        $criteriaName = $params['criteria_name'];

        $valueIndex = $this->getValueIndex($institutionId, $studentId, $academicPeriodId, $criteriaName);

        return $valueIndex;
    }

    public function getValueIndex($institutionId, $studentId, $academicPeriodId, $criteriaName)
    {
        switch ($criteriaName) {
            case 'StatusRepeated':
                $statusRepeatedResults = $this->find()
                    ->where([
                        'student_id' => $studentId,
                    ])
                    ->all();

                $getValueIndex = [];
                foreach ($statusRepeatedResults as $obj) {
                    $statusId = $obj->student_status_id;

                    // for '=' the value index will be in array (valueIndex[threshold] = value)
                    $getValueIndex[$statusId] = !empty($getValueIndex[$statusId]) ? $getValueIndex[$statusId] : 0;
                    $getValueIndex[$statusId] = $getValueIndex[$statusId] + 1;
                }

                return $getValueIndex;
                break;

            case 'Overage':
                $getValueIndex = 0;
                $results = $this->find()
                    ->contain(['Users', 'EducationGrades'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => 1, // student status current
                    ])
                    ->first();

                if (!empty($results)) {
                    $educationGradeId = $results->education_grade_id;
                    $educationProgrammeId = $this->EducationGrades->get($educationGradeId)->education_programme_id;
                    $admissionAge = $this->EducationGrades->getAdmissionAge($educationGradeId);
                    $schoolStartYear = $results->start_year;
                    $birthdayYear = $results->user->date_of_birth->format('Y');

                    $getValueIndex = ($schoolStartYear - $birthdayYear) - $admissionAge;
                }

                return $getValueIndex;
                break;

            case 'Genders':
                $getValueIndex = [];
                $results = $this->find()
                    ->contain(['Users', 'EducationGrades'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => 1, // student status current
                    ])
                    ->first();

                if (!empty($results)) {
                    // for '=' the value index will be in array (valueIndex[threshold] = value)
                    $getValueIndex[$results->user->gender_id] = 1;
                }

                return $getValueIndex;
                break;

            case 'Guardians':
                $getValueIndex = 0;
                $results = $this->find()
                    ->contain(['Users', 'EducationGrades'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => 1, // student status current
                    ])
                    ->first();

                if (!empty($results)) {
                    $Guardians = TableRegistry::get('Student.Guardians');

                    $guardiansData = $Guardians->find()
                        ->where(['student_id' => $results->student_id])
                        ->all()->toArray();

                    $getValueIndex = count($guardiansData);
                }

                return $getValueIndex;
                break;
        }
    }

    public function getReferenceDetails($institutionId, $studentId, $academicPeriodId, $threshold, $criteriaName)
    {
        $referenceDetails = [];

        switch ($criteriaName) {
            case 'StatusRepeated':
                $statusId = $threshold; // it will classified by the status Id
                $results = $this->find()
                    ->contain(['StudentStatuses', 'AcademicPeriods'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => $statusId,
                    ])
                    ->all();

                foreach ($results as $key => $obj) {
                    $title = $obj->student_status->name;
                    $date = $obj->academic_period->name;

                    $referenceDetails[$obj->id] = __($title) . ' (' . $date . ')';
                }

                break;

            case 'Overage':
                $results = $this->find()
                    ->contain(['Users', 'EducationGrades'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => 1, // status enrolled
                    ])
                    ->all();

                foreach ($results as $key => $obj) {
                    $title = $obj->education_grade->name;
                    $date = $obj->user->date_of_birth->format('d/m/Y');

                    $referenceDetails[$obj->id] = __($title) . ' (' . __('Born on') . ': ' . $date . ')';
                }

                break;

            case 'Genders':
                $Genders = TableRegistry::get('User.Genders');

                $results = $this->find()
                    ->contain(['Users', 'EducationGrades'])
                    ->where([
                        'student_id' => $studentId,
                        'student_status_id' => 1, // status enrolled
                    ])
                    ->all();

                foreach ($results as $key => $obj) {
                    $referenceDetails[$obj->id] = __($Genders->get($obj->user->gender_id)->name);
                }

                break;

            case 'Guardians':
                $Guardians = TableRegistry::get('Student.Guardians');

                $results = $Guardians->find()
                    ->contain(['Users', 'GuardianRelations'])
                    ->where(['student_id' => $studentId])
                    ->all();

                if (!$results->isEmpty()) {
                    foreach ($results as $key => $obj) {
                        $guardianName = $obj->user->first_name . ' ' . $obj->user->last_name;
                        $guardianRelation = $obj->guardian_relation->name;

                        $referenceDetails[$obj->guardian_id] = $guardianName . ' (' . __($guardianRelation) . ')';
                    }
                } else {
                    $referenceDetails[] = __('No Guardian');
                }

                break;
        }

        // tooltip only receieved string to be display
        $reference = '';
        foreach ($referenceDetails as $key => $referenceDetailsObj) {
            $reference = $reference . $referenceDetailsObj . '<br/>';
        }

        return $reference;
    }

    public function getInstitutionIdByUser($studentId, $academicPeriodId)
    {
        $institutionId = null;
        $record = $this->find()
            ->where([
                $this->aliasField('student_id') => $studentId,
                $this->aliasField('academic_period_id') => $academicPeriodId,
            ])
            ->order([$this->aliasField('start_date') => 'DESC'])
            ->all();

        if (!$record->isEmpty()) {
            $institutionId = $record->first()->institution_id;
        }

        return $institutionId;
    }
}
