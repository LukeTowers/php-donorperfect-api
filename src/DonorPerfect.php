<?php namespace LukeTowers\DonorPerfectPHP;

use Exception;
use GuzzleHttp\Client;

/**
 * DonorPerfect API class
 *
 * Originally from https://github.com/MikeiLL/donorperfect-php & https://github.com/thinksaydo/donorperfect-php
 */
class DonorPerfect
{
    /**
     * @var string the name this client uses to identify itself to the API for auditing
     */
    protected $appName = 'php-donorperfect-api';

    /**
     * @var string the Base URL used for all requests sent by this client
     */
    protected static $baseUrl = 'https://www.donorperfect.net/prod/xmlrequest.asp';

    /**
     * @var string The API key to use for any requests sent by this client
     */
    protected $apiKey = '';

    /**
     * @var string The username used to authenticate requests sent by this client
     */
    protected $login = '';

    /**
     * @var string The password used to authenticate requests sent by this client
     */
    protected $pass = '';

    /**
     * @var Client The Client object used for requests
     */
    protected $client;

    /**
     * Initialize the API client.
     *
     * @param array|string $credentials The credentials to authenticate with, string if it's an API key,
     *                                  array of ['login' => 'MyUser', 'pass' => 'MyP@ss'] if it's user based authentication
     * @param string $appName The name of the application
     * @return null
     */
    public function __construct($credentials, string $appName = '')
    {
        if (is_string($credentials)) {
            $this->apiKey = $credentials;
        } elseif (is_array($credentials) && !empty($credentials['login']) && !empty($credentials['pass'])) {
            $this->login = $credentials['login'];
            $this->pass = $credentials['pass'];
        } else {
            throw new Exception('Invalid credentials provided to the DonorPerfect API class');
        }

        if (!empty($appName)) {
            if (strlen($appName) > 20) {
                throw new Exception("$appName is too long to use for the DonorPerfect API (max 20 characters).");
            }
            $this->appName = $appName;
        }

        $this->initializeClient();
    }

    /**
     * Initialize the GuzzleHttp/Client instance.
     *
     * @return Client $client
     */
    protected function initializeClient()
    {
        if ($this->client) {
            return $this->client;
        }

        $options = [
            'base_uri' => static::$baseUrl,
            'http_errors' => true,
            'headers' => [
                'Content-Type' => 'text/xml',
            ],
        ];

        return $this->client = new Client($options);
    }

    /**
     * Make the API call to DonorPerfect.
     *
     * @param array $params Key-value array of parameters to be sent on the call (auth keys not required)
     * @throws Exception If the requested API call exceeds the maximum length permitted by the endpoint or otherwise fails
     * @return array $response
     */
    protected function callInternal(array $params)
    {
        // Assemble the API call
        if ($this->apiKey) {
            $args['apikey'] = $this->apiKey;
        } else {
            $args['login'] = $this->login;
            $args['pass'] = $this->pass;
        }
        $args = array_merge($args, $params);

        // Validate the API call before making it
        $url = static::$baseUrl.'?'.http_build_query($args, null, '&', PHP_QUERY_RFC3986);
        if (strlen($url) > 8000) {
            throw new Exception('The DonorPerfect API call exceeds the maximum length permitted (8000 characters)');
        }

        // Make the request
        $response = (string) $this->client->request('GET', '', ['query' => $args])->getBody();

        // Fix values with invalid unescaped XML values
        $response = preg_replace('|(?Umsi)(value=\'DATE:.*\\R*\')|', 'value=\'\'', $response);

        // Turn the response into a usable PHP array
        $response = json_decode(json_encode(simplexml_load_string($response)), true);

        // Handle error messages
        if (array_key_exists('error', $response)) {
            throw new Exception($response['error']);
        }

        // Handle empty responses
        if (empty($response['record'])) {
            return [];
        }

        $records = $response['record'];
        $response = [];
        $isRow = false;

        foreach ($records as $i => $record) {
            // Handle custom multi-record returns
            if (array_key_exists('field', $record)) {
                $record = $record['field'];
                $isRow = true;
            } elseif ($isRow === true) {
                throw new Exception("Error parsing DonorPerfect response at Index $i");
            }

            // Handle custom single-row record returns
            if (array_key_exists('@attributes', $record)) {
                $record = [$record];
            }

            foreach ($record as $ii => $field) {
                $field = $field['@attributes'];

                if ($isRow && is_array($response) && !array_key_exists($i, $response)) {
                    $response[$i] = (object) [];
                } elseif (!$isRow && is_array($response)) {
                    $response = (object) [];
                }

                // Record returned
                if (!empty($field['id'])) {
                    $field['id'] = strtolower($field['id']);
                    $field['value'] = str_ireplace(['`'], ['\''], $field['value']);

                    if ($isRow && is_array($response)) {
                        $response[$i]->{$field['id']} = $field['value'];
                    } else {
                        $response->{$field['id']} = $field['value'];
                    }
                    // Item ID returned when saving or updating
                } else {
                    return (int) $field['value'];
                }
            }
        }

        return $response;
    }

    /**
     * Make a call to a predefined API action with the correctly ordered parameters.
     *
     * @param string $action     The name of the predefined DP action
     * @param array  $parameters The key-value array of parameters to provide to the action ['name' => 'value'] without the @ sign
     * @throws Exception if the call fails
     * @return mixed
     */
    public function call(string $action, array $parameters)
    {
        $paramString = '';
        $i = 0;
        foreach ($parameters as $param => $value) {
            $value = trim($value);

            if (is_numeric($value) && !str_contains($value, 'e')) {
                $value = $value;
            } elseif (empty($value)) {
                $value = 'NULL';
            } else {
                // Ensure quotes are doubled for escaping purposes
                // @see https://api.warrenbti.com/2020/08/03/apostrophes-in-peoples-names/
                $value = str_replace(["'", '"', '%'], ["''", '', '%25'], $value);

                // Wrap the value in quotes
                $value = "'$value'";
            }

            $paramString .= '@'.$param.'='.$value;
            ++$i;
            if ($i !== count($parameters)) {
                $paramString .= ',';
            }
        }

        $params = [
            'action' => $action,
            'params' => $paramString,
        ];

        return $this->callInternal($params);
    }

    /**
     * Make a SQL call to the DonorPerfect API.
     *
     * @param string $sql The raw SQL to send to the API. Any user provided values should be properly
     *                    escaped and provided inline with the SQL.
     * @throws Exception if the call fails
     * @return mixed
     */
    public function callSql($sql)
    {
        $params = [
            'action' => trim(str_ireplace(["\n", "\t", '  ', '  )'], [' ', '', ' ', ' )'], $sql)),
        ];

        return $this->callInternal($params);
    }

    /**
     * Prepare a value to be used as a string.
     *
     * @param mixed $value  The value to prepare
     * @param int   $maxlen The maximum length of the string
     * @throws Exception if the provided value is longer than the max allowed length
     * @return string
     */
    public static function prepareString($value, int $maxlen = null)
    {
        $value = trim($value);

        if (!is_null($maxlen) && strlen($value) > $maxlen) {
            throw new Exception("$value is longer than the max allowed length of $maxlen");
        }

        return $value;
    }

    /**
     * Prepare a value to be used as a numeric datatype.
     *
     * @param mixed $value
     * @throws Exception if the value is not able to be converted to a number
     * @return int|float
     */
    public static function prepareNumeric($value)
    {
        $value = trim($value);

        if (!is_numeric($value) || strpos($value, 'e') !== false) {
            if (empty($value)) {
                return null;
            } else {
                throw new Exception("$value is not numeric");
            }
        }

        return $value + 0;
    }

    /**
     * Prepare a value to be used as a money datatype.
     *
     * @see https://docs.microsoft.com/en-us/sql/t-sql/data-types/money-and-smallmoney-transact-sql?view=sql-server-2016
     * @param mixed $value
     * @return string
     */
    public static function prepareMoney($value)
    {
        $value = $this->prepareNumeric($value);

        return number_format($value, 2, '.', '');
    }

    /**
     * Prepare a value to be used as a date.
     *
     * @param mixed $value
     * @return string
     */
    public static function prepareDate($value)
    {
        // @TODO: Implement the formatter to handle DateTime objects and strtotime strings to convert to MM/DD/YYYY automatically
        return $value;
    }

    /**
     * Prepare a value to be used as a datetime
     *
     * @param mixed $value
     * @return string
     */
    public static function prepareDatetime($value)
    {
        // @TODO: Implement the formatter to handle DateTime objects and strtotime strings to convert to datetime automatically
        return $value;
    }

    /**
     * Prepare the parameters for an API call using the provided input data and parameter configuration
     *
     * @param array $data The input data to use as a source
     * @param array $params The configuration of the parameters in the form of ['named_param' => ['type', 'args', 'to', 'pass', 'to', 'type', 'validator']]
     * @return array
     */
    public static function prepareParams($data, $params)
    {
        $return = [];
        foreach ($params as $param => $config) {
            // Handle explicit values being set
            if (!is_array($config)) {
                $return[$param] = $config;
                continue;
            }

            // Handle a param not being included in the data
            if (!isset($data[$param])) {
                $return[$param] = null;
                continue;
            }

            // Handle all other types
            $config = array_reverse($config);
            $type = ucfirst(array_pop($config));
            if (!empty($config)) {
                $args = array_reverse($config);
            } else {
                $args = [];
            }
            $return[$param] = static::{'prepare' . $type}($data[$param], ...$args);
        }

        return $return;
    }

    /**
     * List all available donors in the system.
     *
     * @return array
     */
    public function listDonors()
    {
        $records = [];
        $pageSize = 500;
        $pageStart = 1;
        $pageEnd = $pageSize;

        while ($pageSize !== null) {
            $response = $this->callSql("
                SELECT
                    *
                FROM (
                    SELECT
                        /*ROW_NUMBER() OVER(ORDER BY dp.first_name, dp.middle_name, dp.last_name ASC) AS row_number,*/
                        ROW_NUMBER() OVER(ORDER BY dp.donor_id ASC) AS row_number,
                        dp.donor_id,
                        dp.first_name,
                        dp.middle_name,
                        dp.last_name,
                        dp.email,
                        dp.address,
                        dp.address2,
                        dp.city,
                        dp.state,
                        dp.zip,
                        dp.country,
                        dp.gift_total
                    FROM dp
                    LEFT JOIN dpudf ON dpudf.donor_id = dp.donor_id
                    WHERE
                        (dp.nomail_reason != 'IA'
                        AND dp.nomail_reason != 'DE')
                        OR dp.nomail_reason IS NULL
                    /*ORDER BY dp.first_name, dp.middle_name,dp.last_name ASC*/
                ) AS tmp
                WHERE tmp.row_number BETWEEN {$pageStart} AND {$pageEnd}
            ");

            // Handle records returned from the current page
            if (is_array($response) && count($response) > 0) {
                // Add this page's records to the running total
                foreach ($response as $record) {
                    $records[] = $record;
                }
                unset($record);

                // Select the next page
                $pageStart += $pageSize;
                $pageEnd += $pageSize;
            } else {
                // Signal that there are no more records to be obtained
                $pageSize = null;
            }
        }

        return $records;
    }

    //
    // PREDEFINED PROCEDURES
    //

    /**
     * Searching for a Donor—used to search for the donor based on several search criteria
     * (similar to the search functionality offered in DPO). You can use “%” for wildcards.
     *
     * @param array $data
     * @return array
     */
    public function dp_donorsearch($data)
    {
        return $this->call('dp_donorsearch', static::prepareParams($data, [
            'donor_id'   => ['numeric'],
            'last_name'  => ['string', 75],
            'first_name' => ['string', 50],
            'opt_line'   => ['string', 100],
            'address'    => ['string', 100],
            'city'       => ['string', 50],
            'state'      => ['string', 30],
            'zip'        => ['string', 20],
            'country'    => ['string', 30],
            'filter_id'  => null,
            'user_id'    => $this->appName,
        ]));
    }

    /**
     * Saving a New/Existing Donor—used to save changes to the existing donor/constituent or
     * save the new donor/constituent into the DPO system.
     *
     * @param array $data
     * @return integer the donor_id of the created donor
     */
    public function dp_savedonor($data)
    {
        return $this->call('dp_savedonor', static::prepareParams($data, [
            'donor_id'        => ['numeric'], // Enter 0 (zero) to create a new donor/constituent record or an existing donor_id. Please note: If you are updating an existing donor, all existing values for the fields specified below will be overwritten by the values you send with this API call.
            'first_name'      => ['string', 50], //
            'last_name'       => ['string', 75], //
            'middle_name'     => ['string', 50], //
            'suffix'          => ['string', 50], //
            'title'           => ['string', 50], //
            'salutation'      => ['string', 130], //
            'prof_title'      => ['string', 100], //
            'opt_line'        => ['string', 100], // Enter as NULL if other field value not required.
            'address'         => ['string', 100], //
            'address2'        => ['string', 100], //
            'city'            => ['string', 50], //
            'state'           => ['string', 30], //
            'zip'             => ['string', 20], //
            'country'         => ['string', 30], //
            'address_type'    => ['string', 30], //
            'home_phone'      => ['string', 40], //
            'business_phone'  => ['string', 40], //
            'fax_phone'       => ['string', 40], //
            'mobile_phone'    => ['string', 40], //
            'email'           => ['string', 75], //
            'org_rec'         => ['string', 1], // Enter 'Y' to check the Org Rec field (indicating an organizational record) or 'N' to leave it unchecked indicating an individual record.
            'donor_type'      => ['string', 30], // Set to 'IN' for Individual donor or 'CO' for Corporate donor. You can also check your DPO system for additional DONOR_TYPE field value choices.
            'nomail'          => ['string', 1], //
            'nomail_reason'   => ['string', 30], //
            'narrative'       => ['string', 2147483647], //
            'donor_rcpt_type' => ['string', 1], // 'I' for individual or 'C' for consolidated receipting preference
            'user_id'         => $this->appName,
        ]));
    }

    /**
     * Returns a predefined set of fields associated with all gifts given by the specified donor.
     *
     * @param array $data
     * @return array
     */
    public function dp_gifts($data)
    {
        return $this->call('dp_gifts', static::prepareParams($data, [
            'donor_id'   => ['numeric'],
        ]));
    }

    /**
     * Save changes to an existing gift or save a new gift into the DPO system
     *
     * @param array $data
     * @return integer The gift_id of the created / updated gift
     */
    public function dp_savegift($data)
    {
        return $this->call('dp_savegift', static::prepareParams($data, [
            'gift_id'             => ['numeric'], // Enter 0 in this field to create a new gift or the gift ID of an existing gift. Please note: If you are updating an existing gift, all existing values for the fields specified below will be overwritten by the values you send with this API call.
            'donor_id'            => ['numeric'], // Enter the donor_id of the person for whom the gift will be created
            'record_type'         => ['string', 1], // Set as 'G' for a regular gift or for an individual split gift entry within a split gift (i.e.; not the Main top level split), ‘P’ for Pledge, 'M' for the Main gift in a split gift
            'gift_date'           => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'amount'              => ['money'], //
            'gl_code'             => ['string', 30], // If desired, enter the CODE value of the General Ledger code this gift will be associated with. Code values can be found in the DPCODES table. Note: If you are not setting a gl_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'solicit_code'        => ['string', 30], // If desired, enter the CODE value of the desired solicit code Note: If you are not setting a solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'sub_solicit_code'    => ['string', 30], // If desired, enter the CODE value of the desired sub-solicit code Note: If you are not setting a sub_solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'campaign'            => ['string', 30], // If desired, enter the CODE value of the desired campaign code Note: If you are not setting a campaign code value in this field, set the field to null. Do not ever set this field to empty ('').
            'gift_type'           => ['string', 30], //
            'split_gift'          => ['string', 1], // Set to 'Y' for each of the splits within a split gift but set to 'N' for the Main gift in a split gift or for any gift that is not a split gift.
            'pledge_payment'      => ['string', 1], //
            'reference'           => ['string', 100], // The associated SafeSave Transaction ID number is normally entered here.
            'memory_honor'        => ['string', 30], //
            'gfname'              => ['string', 50], //
            'glname'              => ['string', 75], //
            'fmv'                 => ['money'], //
            'batch_no'            => ['numeric'], //
            'gift_narrative'      => ['string', 4000], //
            'ty_letter_no'        => ['string', 30], // Note: If you are not setting a ty_letter_no code value in this field, set the field to null. Do not ever set this field to empty ('').
            'glink'               => ['numeric'], // In a split gift (not the Main), set the glink value to the gift_id value of the Main gift in the split Also, if you are creating a soft credit gift (record_type='S'), use the GLINK field to identify the gift_id of the actual gift. Note: If you are not setting a gift_id value in this field, set the field to null. Do not ever set this field to empty ('') or zero.
            'plink'               => ['numeric'], // This field should be blank for a nonrecurring gift but if you are creating a gift that is to be associated with a pledge, set the plink value of the gift to the gift_ID value of the associated pledge. Note: If you are not setting a gift_id value in this field, set the field to null. Do not ever set this field to empty ('') or zero.
            'nocalc'              => ['string', 1], //
            'receipt'             => ['string', 1], //
            'old_amount'          => ['money'], //
            'user_id'             => $this->appName,
            'gift_aid_date'       => ['datetime'], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'gift_aid_amt'        => ['money'], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'gift_aid_eligible_g' => ['string', 1], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'currency'            => ['string', 3], // If you use the multi-currency feature, enter appropriate code value per your currency field – e.g; 'USD', 'CAD', etc.
            'receipt_delivery_g'  => ['string', 1], // This field sets receipt delivery preference for the specified gift. Supply one of the following single letter code values: • N = do not acknowledge • E = email • B = email and letter • L = letter
        ]));
    }

    /**
     * Create or save changes to a pledge. It is not used for pledge payments.
     * In DPO, there is a parent pledge (which this command is used to create) that
     * shows up in the DPO pledges tab. Then, when pledge payments are made, they are
     * created as gifts (record_type=’G') using the dp_savegift procedure with a gift_type of ‘G’
     * like a regular gift, but add in a ‘plink’ value with the gift_id of the parent pledge.
     *
     * @param array $data
     * @return integer The gift_id of the created / updated gift used as a pledge
     */
    public function dp_savepledge($data)
    {
        return $this->call('dp_savepledge', static::prepareParams($data, [
            'gift_id'             =>['numeric'], // Enter 0 in this field to create a new pledge or the gift ID of an existing pledge.
            'donor_id'            =>['numeric'], // Enter the donor_id of the person for whom the pledge is being created/updated
            'gift_date'           =>['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'start_date'          =>['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'total'               =>['money'], // Enter either the total amount to be pledged (the sum of all the expected payment amounts) or enter 0 (zero) if the pledge amount is to be collected adinfinitum
            'bill'                =>['money'], // Enter the individual monthly/quarterly/annual billing amount
            'frequency'           =>['string', 30], // Enter one of: M (monthly), Q (quarterly), S (semi-annually), A (annually)
            'reminder'            =>['string', 1], // Sets the pledge reminder flag
            'gl_code'             =>['string', 30], // Note: If you are not setting a gl_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'solicit_code'        =>['string', 30], // Note: If you are not setting a solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'initial_payment'     =>['string', 1], // Set to ’Y’ for intial payment, otherwise ‘N’
            'sub_solicit_code'    =>['string', 30], // Note: If you are not setting a sub_solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'writeoff_amount'     =>['money'], //
            'writeoff_date'       =>['datetime'], //
            'user_id'             =>['string', 20], //,
            'campaign'            =>['string', 30], // Note: If you are not setting a campaign_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'membership_type'     =>['string', 30], // Or NULL
            'membership_level'    =>['string', 30], // Or NULL
            'membership_enr_date' =>['datetime'], // Or NULL
            'membership_exp_date' =>['datetime'], // Or NULL
            'membership_link_ID'  =>['numeric'], // Or NULL
            'address_id'          =>['numeric'], // Or NULL
            'gift_narrative'      =>['string', 4000], // Or NULL
            'ty_letter_no'        =>['string', 30], // Or NULL
            'vault_id'            =>['numeric'], // This field must be populated from the Vault ID number returned by SafeSave for the pledge to be listed as active in the user interface.
            'receipt_delivery_g'  =>['string', 1], // ‘E’ for email, ‘B’ for both email and letter, ‘L’ for letter, ‘N’ for do not acknowledge or NULL
            'contact_id'          =>['numeric'], // Or NULL
        ]));
    }
}
