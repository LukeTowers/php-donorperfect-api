<?php namespace LukeTowers\DonorPerfectPHP;

class Schema
{
    protected $tables = [
        'DPGIFT',
        'DPGIFTUDF',
    ];




    protected $schemas = [
        'DP' => ['columns' => [
            'donor_id'                => ['type' => 'numeric', 'nullable' => false],
            'first_name'              => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'last_name'               => ['type' => 'nvarchar', 'max_char' => 75, 'nullable' => false],
            'middle_name'             => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'suffix'                  => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'title'                   => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'salutation'              => ['type' => 'nvarchar', 'max_char' => 130, 'nullable' => true],
            'prof_title'              => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'opt_line'                => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'address'                 => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'address2'                => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'city'                    => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'state'                   => ['type' => 'nvarchar', 'max_char' => 30, 'nullable' => true],
            'zip'                     => ['type' => 'nvarchar', 'max_char' => 20, 'nullable' => true],
            'country'                 => ['type' => 'nvarchar', 'max_char' => 30, 'nullable' => true],
            'address_type'            => ['type' => 'nvarchar', 'max_char' => 30, 'nullable' => true],
            'home_phone'              => ['type' => 'nvarchar', 'max_char' => 40, 'nullable' => true],
            'business_phone'          => ['type' => 'nvarchar', 'max_char' => 40, 'nullable' => true],
            'fax_phone'               => ['type' => 'nvarchar', 'max_char' => 40, 'nullable' => true],
            'mobile_phone'            => ['type' => 'nvarchar', 'max_char' => 40, 'nullable' => true],
            'email'                   => ['type' => 'nvarchar', 'max_char' => 75, 'nullable' => true],
            'org_rec'                 => ['type' => 'nvarchar', 'max_char' => 1, 'nullable' => true],
            'donor_type'              => ['type' => 'nvarchar', 'max_char' => 30, 'nullable' => true],
            'nomail'                  => ['type' => 'nvarchar', 'max_char' => 1, 'nullable' => false],
            'nomail_reason'           => ['type' => 'nvarchar', 'max_char' => 30, 'nullable' => true],
            'narrative'               => ['type' => 'nvarchar', 'max_char' => -1, 'nullable' => true],
            'tag_date'                => ['type' => 'datetime', 'nullable' => true],
            'initial_gift_date'       => ['type' => 'datetime', 'nullable' => true],
            'last_contrib_date'       => ['type' => 'datetime', 'nullable' => true],
            'last_contrib_amt'        => ['type' => 'money', 'nullable' => true],
            'ytd'                     => ['type' => 'money', 'nullable' => true],
            'ly_ytd'                  => ['type' => 'money', 'nullable' => true],
            'ly2_ytd'                 => ['type' => 'money', 'nullable' => true],
            'ly3_ytd'                 => ['type' => 'money', 'nullable' => true],
            'ly4_ytd'                 => ['type' => 'money', 'nullable' => true],
            'ly5_ytd'                 => ['type' => 'money', 'nullable' => true],
            'ly6_ytd'                 => ['type' => 'money', 'nullable' => true],
            'cytd'                    => ['type' => 'money', 'nullable' => true],
            'ly_cytd'                 => ['type' => 'money', 'nullable' => true],
            'ly2_cytd'                => ['type' => 'money', 'nullable' => true],
            'ly3_cytd'                => ['type' => 'money', 'nullable' => true],
            'ly4_cytd'                => ['type' => 'money', 'nullable' => true],
            'ly5_cytd'                => ['type' => 'money', 'nullable' => true],
            'ly6_cytd'                => ['type' => 'money', 'nullable' => true],
            'autocalc1'               => ['type' => 'money', 'nullable' => true],
            'autocalc2'               => ['type' => 'money', 'nullable' => true],
            'autocalc3'               => ['type' => 'money', 'nullable' => true],
            'gift_total'              => ['type' => 'money', 'nullable' => true],
            'gifts'                   => ['type' => 'numeric', 'nullable' => true],
            'max_date'                => ['type' => 'datetime', 'nullable' => true],
            'max_amt'                 => ['type' => 'money', 'nullable' => true],
            'avg_amt'                 => ['type' => 'money', 'nullable' => true],
            'yrs_donated'             => ['type' => 'numeric', 'nullable' => true],
            'created_by'              => ['type' => 'nvarchar', 'max_char' => 20, 'nullable' => true],
            'created_date'            => ['type' => 'datetime', 'nullable' => true],
            'modified_by'             => ['type' => 'nvarchar', 'max_char' => 20, 'nullable' => true],
            'modified_date'           => ['type' => 'datetime', 'nullable' => true],
            'donor_rcpt_type'         => ['type' => 'nchar', 'max_char' => 1, 'nullable' => true],
            'address3'                => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'address4'                => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'ukcounty'                => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'gift_aid_eligible'       => ['type' => 'nchar', 'max_char' => 1, 'nullable' => true],
            'initial_temp_record_id'  => ['type' => 'numeric', 'nullable' => true],
            'frequent_temp_record_id' => ['type' => 'numeric', 'nullable' => true],
            'recent_temp_record_id'   => ['type' => 'numeric', 'nullable' => true],
            'import_id'               => ['type' => 'numeric', 'nullable' => true],
            'receipt_delivery'        => ['type' => 'nvarchar', 'max_char' => 1, 'nullable' => true],
            'no_email'                => ['type' => 'nvarchar', 'max_char' => 1, 'nullable' => true],
            'no_email_reason'         => ['type' => 'nvarchar', 'max_char' => 200, 'nullable' => true],
            'email_type'              => ['type' => 'nvarchar', 'max_char' => 20, 'nullable' => true],
            'email_status'            => ['type' => 'nvarchar', 'max_char' => 50, 'nullable' => true],
            'email_status_date'       => ['type' => 'datetime', 'nullable' => true],
            'opt_out_source'          => ['type' => 'nvarchar', 'max_char' => 200, 'nullable' => true],
            'opt_out_reason'          => ['type' => 'nvarchar', 'max_char' => 200, 'nullable' => true],
            'CC_donor_import_time'    => ['type' => 'datetime', 'nullable' => true],
            'cc_contact_id'           => ['type' => 'bigint', 'nullable' => true],
            'wl_import_id'            => ['type' => 'numeric', 'nullable' => true],
            'WL_Action'               => ['type' => 'nchar', 'max_char' => 1, 'nullable' => true],
            'geocoding_lattitude'     => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'geocoding_longitude'     => ['type' => 'nvarchar', 'max_char' => 100, 'nullable' => true],
            'quickbooks_customer_id'  => ['type' => 'nvarchar', 'max_char' => 20, 'nullable' => true],
        ]],
    ];

    /**
     * Get the schema for the given DP table
     *
     * @param string $table
     * @return array|null
     */
    public function getSchema(string $table) {
        $table = strtoupper($table);
        return @$this->schemas[$table];
    }
}