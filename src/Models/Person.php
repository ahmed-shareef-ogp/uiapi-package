<?php

namespace Ogp\UiApi\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Validation\Rule;

class Person extends BaseModel
{
    protected $table = 'people';

    protected array $searchable = [
        'id',
        'first_name_eng',
    ];

    protected $appends = ['full_name'];

    protected array $computedAttributeDependencies = [
        'full_name' => ['first_name_eng', 'middle_name_eng', 'last_name_eng'],
    ];

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => collect([
                $this->first_name_eng,
                $this->middle_name_eng,
                $this->last_name_eng,
            ])
                ->filter() // removes null, empty strings
                ->join(' ')
        );
    }

    public function apiSchema(): array
    {
        return [
            'columns' => [
                'full_name' => [
                    'hidden' => false,
                    'key' => 'full_name',
                    'label' => ['dv' => 'ނަން', 'en' => 'Full Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],
                'id' => [
                    'hidden' => true,
                    'key' => 'id',
                    'label' => ['dv' => 'އައިޑީ', 'en' => 'Id'],
                    'lang' => ['en', 'dv'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => false,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|integer|unique:entries,id',
                    'sortable' => true,
                ],
                'first_name_eng' => [
                    'hidden' => false,
                    'key' => 'first_name_eng',
                    'label' => ['dv' => 'ފުރަތަމަ ނަން', 'en' => 'First Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'middle_name_eng' => [
                    'hidden' => false,
                    'key' => 'middle_name_eng',
                    'label' => ['dv' => 'މެދު ނަން', 'en' => 'Middle Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'last_name_eng' => [
                    'hidden' => false,
                    'key' => 'last_name_eng',
                    'label' => ['dv' => 'ފަހު ނަން', 'en' => 'Last Name'],
                    'lang' => ['en'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'first_name_div' => [
                    'hidden' => false,
                    'key' => 'first_name_div',
                    'label' => ['dv' => 'ފުރަތަމަ ނަން', 'en' => 'First Name'],
                    'lang' => ['dv'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'middle_name_div' => [
                    'hidden' => false,
                    'key' => 'middle_name_div',
                    'label' => ['dv' => 'މެދު ނަން', 'en' => 'Middle Name'],
                    'type' => 'string',
                    'lang' => ['dv'],
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'last_name_div' => [
                    'hidden' => false,
                    'key' => 'last_name_div',
                    'label' => ['dv' => 'ފަހު ނަން', 'en' => 'Last Name'],
                    'type' => 'string',
                    'lang' => ['dv'],
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'formField' => true,
                    'fieldComponent' => 'textInput',
                    'validationRule' => 'required|string|max:255',
                    'sortable' => true,
                ],
                'date_of_birth' => [
                    'hidden' => false,
                    'key' => 'date_of_birth',
                    'label' => ['dv' => 'އުފަން ދުވަސް', 'en' => 'Date of Birth'],
                    'type' => 'date',
                    'displayType' => 'date',
                    'inputType' => 'datepicker',
                    'lang' => ['en', 'dv'],
                    'sortable' => true,
                ],
                'date_of_death' => [
                    'hidden' => false,
                    'key' => 'date_of_death',
                    'label' => ['dv' => 'ނިޔާވި ދުވަސް', 'en' => 'Date of Death'],
                    'type' => 'date',
                    'displayType' => 'date',
                    'inputType' => 'datepicker',
                    'lang' => ['en', 'dv'],
                    'sortable' => true,
                ],
                'gender' => [
                    'hidden' => false,
                    'key' => 'gender',
                    'label' => ['dv' => 'ޖިންސު', 'en' => 'Gender'],
                    'type' => 'string',
                    'lang' => ['en', 'dv'],
                    'displayType' => 'chip',
                    'inputType' => 'select',
                    'formField' => true,
                    'inlineEditable' => true,
                    'chip' => [
                        'M' => [
                            'label' => ['dv' => 'ފިރިހެން', 'en' => 'Maale'],
                            'color' => 'primary',
                            'prependIcon' => 'user',
                        ],
                        'F' => [
                            'label' => ['dv' => 'އަންހެނެ', 'en' => 'Femaale'],
                            'color' => 'success',
                            'prependIcon' => 'users',
                        ],
                    ],
                    'select' => [
                        'type' => 'select',
                        'label' => ['dv' => 'ޖިންސު', 'en' => 'Gender'],
                        'mode' => 'self',
                        'items' => [
                            ['itemTitleDv' => '-ނެތް-', 'itemTitleEn' => '-empty-', 'itemValue' => ''],
                            ['itemTitleDv' => 'ފިރިހެން', 'itemTitleEn' => 'Male', 'itemValue' => 'M'],
                            ['itemTitleDv' => 'އަންހެނެ', 'itemTitleEn' => 'Female', 'itemValue' => 'F'],
                        ],
                        'itemTitle' => [
                            'dv' => 'itemTitleDv',
                            'en' => 'itemTitleEn',
                        ],
                        'itemValue' => 'itemValue',
                    ],
                ],
                'contact' => [
                    'hidden' => false,
                    'key' => 'contact',
                    'label' => ['dv' => 'ގުޅޭނެ ނަންބަރު', 'en' => 'Contact'],
                    'type' => 'json',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'lang' => ['en', 'dv'],
                ],
                'father_name' => [
                    'hidden' => false,
                    'key' => 'father_name',
                    'label' => ['dv' => 'ބައްޕަގެ ނަން', 'en' => 'Fathers Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'lang' => ['dv'],
                ],

                'country_id' => [
                    'hidden' => true,
                    'key' => 'country_id',
                    'label' => ['dv' => 'ޤައުމުގެ އައިޑީ', 'en' => 'Country ID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'inputType' => 'select',
                    'formField' => true,
                    'lang' => ['en', 'dv'],
                    'select' => [
                        'multiple' => true,
                        'label' => ['dv' => 'ޤައުމު', 'en' => 'Country'],
                        'mode' => 'relation',
                        'relationship' => 'country',
                        'itemTitle' => [
                            'dv' => 'name_div',
                            'en' => 'name_eng',
                        ],
                        'itemValue' => 'id',
                        'sourceModel' => 'Country',
                        'value' => 'country_id',
                    ],
                ],
                'mother_name' => [
                    'hidden' => false,
                    'key' => 'mother_name',
                    'label' => ['dv' => 'މަންމަގެ ނަން', 'en' => 'Mothers Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'lang' => ['dv'],
                ],
                'guardian' => [
                    'hidden' => false,
                    'key' => 'guardian',
                    'label' => ['dv' => 'ބެލެނިވެރިޔާ', 'en' => 'Guardian Name'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'lang' => ['dv'],
                ],
                'remarks' => [
                    'hidden' => false,
                    'key' => 'remarks',
                    'label' => ['dv' => 'ރިމާކްސް', 'en' => 'Remarks'],
                    'type' => 'string',
                    'displayType' => 'text',
                    'inputType' => 'textField',
                    'lang' => ['dv'],
                ],
                'police_pid' => [
                    'hidden' => false,
                    'key' => 'police_pid',
                    'label' => ['dv' => 'ޕޮލިސް ޕީއައިޑީ', 'en' => 'Police PID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'inputType' => 'numberField',
                    'lang' => ['en', 'dv'],
                ],
                'crpc_id' => [
                    'hidden' => false,
                    'key' => 'crpc_id',
                    'label' => ['dv' => 'ސީއާރްޕީސީ އައިޑީ', 'en' => 'CRPC ID'],
                    'type' => 'number',
                    'displayType' => 'text',
                    'inputType' => 'numberField',
                    'lang' => ['en', 'dv'],
                ],
                'is_in_custody' => [
                    'hidden' => false,
                    'key' => 'is_in_custody',
                    'label' => ['dv' => 'ހުރީ ހައްޔަރުގަ', 'en' => 'Is in custody'],
                    'type' => 'boolean',
                    'lang' => ['en', 'dv'],
                    'sortable' => true,
                    'displayType' => 'checkbox',
                    'inputType' => 'checkbox',
                    'displayProps' => [
                        'label' => ' ',
                        'color' => 'primary',
                    ],
                ],
                'created_at' => [
                    'hidden' => true,
                    'key' => 'created_at',
                    'label' => ['dv' => 'އުފެއްދި ތާރީޚް', 'en' => 'Created At'],
                    'type' => 'datetime',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],
                'updated_at' => [
                    'hidden' => true,
                    'key' => 'updated_at',
                    'label' => ['dv' => 'އަޕްޑޭޓްކުރި ތާރީޚް', 'en' => 'Updated At'],
                    'type' => 'datetime',
                    'displayType' => 'text',
                    'lang' => ['en', 'dv'],
                ],
            ],
        ];
    }

    public static function rules(?int $id = null): array
    {
        return $id === null ? static::rulesForCreate() : static::rulesForUpdate($id);
    }

    public static function baseRules(): array
    {
        return [
            'id' => ['sometimes', 'integer'],
            'full_name' => ['sometimes', 'string'],
            'first_name_eng' => ['sometimes', 'string', 'max:255'],
            'middle_name_eng' => ['nullable', 'string', 'max:255'],
            'last_name_eng' => ['sometimes', 'string', 'max:255'],
            'first_name_div' => ['nullable', 'string', 'max:255'],
            'middle_name_div' => ['nullable', 'string', 'max:255'],
            'last_name_div' => ['nullable', 'string', 'max:255'],
            'first_name_urd' => ['nullable', 'string', 'max:255'],
            'middle_name_urd' => ['nullable', 'string', 'max:255'],
            'last_name_urd' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'date_of_death' => ['nullable', 'date', 'after_or_equal:date_of_birth'],
            'gender' => ['nullable', 'in:M,F'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'guardian' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'police_pid' => ['nullable', 'integer'],
            'crpc_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'is_in_custody' => ['nullable', 'boolean'],
            'contact' => ['nullable', 'array'],
        ];
    }

    public static function rulesForCreate(): array
    {
        $rules = static::baseRules();
        // Create requires key name fields
        $rules['first_name_eng'] = ['required', 'string', 'max:255'];
        $rules['last_name_eng'] = ['required', 'string', 'max:255'];
        $rules['gender'] = ['required', 'in:M,F'];
        // id must be unique if provided
        $rules['id'] = ['sometimes', 'integer', 'unique:people,id'];
        return $rules;
    }

    public static function rulesForUpdate(?int $id = null): array
    {
        $rules = static::baseRules();
        // On update, allow partial updates
        $rules['first_name_eng'] = ['sometimes', 'string', 'max:255'];
        $rules['last_name_eng'] = ['sometimes', 'string', 'max:255'];
        // id must be present and remain unique (ignore self when provided)
        $rules['id'] = $id !== null
            ? ['required', 'integer', Rule::unique('people', 'id')->ignore($id)]
            : ['required', 'integer'];
        return $rules;
    }

    public static function validationMessages(): array
    {
        return [
            'id.unique' => 'This record already exists.',

            'first_name_eng.required' => 'First name (English) is required.',
            'middle_name_eng.required' => 'Middle name (English) is required.',
            'last_name_eng.required' => 'Last name (English) is required.',

            'first_name_div.required' => 'First name (Dhivehi) is required.',
            'middle_name_div.required' => 'Middle name (Dhivehi) is required.',
            'last_name_div.required' => 'Last name (Dhivehi) is required.',

            'date_of_birth.date' => 'Date of birth must be a valid date.',
            'date_of_death.date' => 'Date of death must be a valid date.',
            'date_of_death.after_or_equal' => 'Date of death cannot be earlier than date of birth.',

            'gender.in' => 'Gender must be either Male or Female.',

            'contact.array' => 'Contact information must be a valid JSON object.',

            'police_pid.integer' => 'Police PID must be a number.',
            'crpc_id.integer' => 'CRPC ID must be a number.',

            'country_id.exists' => 'Selected country does not exist.',

            'is_in_custody.boolean' => 'Custody status must be true or false.',
        ];
    }

    public function country()
    {
        return $this->belongsTo(\App\Models\Country::class, 'country_id');
    }
}
