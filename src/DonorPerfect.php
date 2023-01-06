<?php namespace LukeTowers\DonorPerfectPHP;

use Exception;
use GuzzleHttp\Client;

/**
 * DonorPerfect API class
 *
 * Originally from https://github.com/MikeiLL/donorperfect-php & https://github.com/thinksaydo/donorperfect-php
 *
 * @TODO:
 * - Implement better dp_savegift() method that only requires the essential data to record gifts and has proper
 *   defaults set for all the other fields
 * - Add ability to specify defaults that are instance specific but not transaction specific when instantiating
 *   API object
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
        $args = [];
        $relativeUrl = '?';

        // Assemble the API call
        if ($this->apiKey) {
            // API key doesn't support being URL-encoded
            $relativeUrl .= 'apikey=' . $this->apiKey . '&';
        } else {
            $args['login'] = $this->login;
            $args['pass'] = $this->pass;
        }
        $args = array_merge($args, $params);

        // Validate the API call before making it
        $relativeUrl .= http_build_query($args, null, '&', PHP_QUERY_RFC3986);
        // encode any + signs
        $relativeUrl = str_replace('+', '%2B', $relativeUrl);

        if (strlen(static::$baseUrl . $relativeUrl) > 8000) {
            throw new Exception('The DonorPerfect API call exceeds the maximum length permitted (8000 characters)');
        }
        // Make the request
        $response = (string) $this->client->request('GET', $relativeUrl)->getBody();

        // Fix values with invalid unescaped XML values
        $response = preg_replace('|(?Umsi)(value=\'DATE:.*\\R*\')|', 'value=\'\'', $response);

        // Turn the response into a usable PHP array
        $response = json_decode(json_encode(simplexml_load_string($response)), true);

        // Handle error messages
        if (array_key_exists('error', $response)) {
            throw new Exception($response['error']);
        } elseif (isset($response['field']['@attributes']['value']) && $response['field']['@attributes']['value'] === 'false') {
            throw new Exception($response['field']['@attributes']['reason']);
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

            if (is_numeric($value)
                && strpos($value, 'e') === false
                && strpos($value, '+') === false
                && $param != 'CardExpirationDate') {
                $value = $value;
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (empty($value)) {
                $value = 'NULL';
            } elseif (is_array($value)) {
                $value = "N'" . implode($value, '|') . "'";
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
        // Remove all formatting whitespace while leaving whitespace that is part of value strings
        $in_quote = false;
        $output = '';

        for ($i = 0; $i < strlen($sql); $i++) {
            if ($sql[$i] == "'" || $sql[$i] == '"') {
                $in_quote = !$in_quote;
            }
            if (($sql[$i] == ' ' || $sql[$i] == "\n" || $sql[$i] == "\r") && !$in_quote) {
                if (empty($output) || substr($output, -1) == ' ') {
                    continue;
                }
                $output .= ' ';
            } else {
                $output .= $sql[$i];
            }
        }

        $params = [
            'action' => $output
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
        $value = static::prepareNumeric($value);

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
     * Prepare a value to be used as an array of values
     *
     * @param array $value
     * @return array
     */
    public static function prepareArray($value)
    {
        if (!is_array($value)) {
            throw new Exception("The provided value is not a valid array");
        }

        return $value;
    }

    /**
     * Prepare a value to be used as a boolean
     *
     * @param mixed $value
     * @return bool $value
     */
    public static function prepareBool($value)
    {
        return (bool) $value;
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
     * Get all the tables present in the DP database
     *
     * @return array
     */
    public function getTables()
    {
        return $this->callSql("
            SELECT
                *
            FROM
                SYSOBJECTS
            WHERE
                xtype = 'U';
        ");

        // Appears that DP has disabled the permissions required for the following query
        // prior to 2021-07-15
        // return $this->callSql("
        //     SELECT
        //         *
        //     FROM
        //         INFORMATION_SCHEMA.TABLES;
        // ");
    }

    /**
     * Get all the columns present in the provided table
     *
     * @param string $table
     * @return array
     */
    public function getColumns(string $table)
    {
        return $this->callSql("EXEC sp_columns $table");
    }

    /**
     * Get an array with all the tables that have at least 1 row along with the number of rows present in the given table
     *
     * @return array
     */
    public function getTablesAndRowCounts()
    {
        return $this->callSql("
            SELECT
                t.NAME AS TableName,
                p.rows AS RowCounts
            FROM
                sys.tables t
            INNER JOIN
                sys.indexes i ON t.OBJECT_ID = i.object_id
            INNER JOIN
                sys.partitions p ON i.object_id = p.OBJECT_ID AND i.index_id = p.index_id
            WHERE
                t.is_ms_shipped = 0
                AND p.rows > 0
            GROUP BY
                t.Name, p.Rows
            ORDER BY
                t.Name
        ");
    }

    /**
     * List the available values from DPCODES for a given field name
     *
     * @param string $fieldName
     * @return mixed
     */
    public function getFieldValues($fieldName)
    {
        $lastId = 0;
        $finished = false;
        $pageSize = 500;
        $result = [];

        do {
            $response = $this->callSql("
                SELECT TOP $pageSize
                    *
                FROM
                    DPCODES
                WHERE
                    field_name = '$fieldName'
                AND
                    code_id > $lastId
                ORDER BY
                    code_id
            ");

            if (is_array($response)) {
                $recordsReturned = count($response);
                if ($recordsReturned > 0) {
                    $result += $response;

                    if ($recordsReturned < $pageSize) {
                        $finished = true;
                    } else {
                        $lastId = end($response)->code_id;
                    }
                } else {
                    $finished = true;
                }
            } else {
                $finished = true;
            }
        } while (!$finished);

        return $result;
    }

    /**
     * Get all the data for the provided donor
     *
     * @param int $donorId
     * @return array|null
     */
    public function getDonor($donorId)
    {
        return $this->callSql("
            SELECT TOP 1
                *
            FROM
                DP
            WHERE donor_id = $donorId
        ");
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
        // nomail is required for dp_savedonor, ensure it is present and valid
        if (!isset($data['nomail']) || $data['nomail'] != 'Y') {
          $data['nomail'] = 'N';
        }
        // receipt delivery defaults to L if unspecified, make it explicit
        if (!isset($data['receipt_delivery'])) {
          $data['receipt_delivery'] = 'L';
        }

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
            'receipt_delivery'=> ['string', 1], // 'B' for letter and email, 'L' for letter, 'E' email, 'N' do not acknowledge
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
            'transaction_id'      => ['numeric'], // The associated SafeSave Transaction ID number is normally entered here. This field supersedes the @reference field which was previously used for this purpose.
            'memory_honor'        => ['string', 30], //
            'gfname'              => ['string', 50], //
            'glname'              => ['string', 75], //
            'fmv'                 => ['money'], //
            'batch_no'            => ['numeric'], //
            'gift_narrative'      => ['string', 4000], //
            'ty_letter_no'        => ['string', 30], // Note: If you are not setting a ty_letter_no code value in this field, set the field to null. Do not ever set this field to empty ('').
            'glink'               => ['numeric'], // In a split gift (not the Main), set the glink value to the gift_id value of the Main gift in the split Also, if you are creating a soft credit gift (record_type='S'), use the GLINK field to identify the gift_id of the actual gift. Note: If you are not setting a gift_id value in this field, set the field to null. Do not ever set this field to empty ('') or zero.
            'plink'               => ['numeric'], // This field should be blank for a nonrecurring gift but if you are creating a gift that is to be associated with a pledge, set the plink value of the gift to the gift_ID value of the associated pledge. Note: If you are not setting a gift_id value in this field, set the field to null. Do not ever set this field to empty ('') or zero.
            'nocalc'              => ['string', 1], // 	The standard value for this field is @nocalc='N'.  This field must be set to 'N' for gifts to be reflected in the reports dashboard.
            'receipt'             => ['string', 1], //
            'old_amount'          => ['money'], //
            'user_id'             => $this->appName,
            'gift_aid_date'       => ['datetime'], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'gift_aid_amt'        => ['money'], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'gift_aid_eligible_g' => ['string', 1], // This field relates to the UK based Gift Aid Program. See Supplemental Information > Gift Aid Program for more information.
            'currency'            => ['string', 3], // If you use the multi-currency feature, enter appropriate code value per your currency field – e.g; 'USD', 'CAD', etc.
            'receipt_delivery_g'  => ['string', 1], // This field sets receipt delivery preference for the specified gift. Supply one of the following single letter code values: • N = do not acknowledge • E = email • B = email and letter • L = letter
            'acknowledgepref'     => ['string', 3], // Used in Canadian DonorPerfect systems to  indicate official receipt acknowledgement preference code: • 1AR – Acknowledge/Receipt • 2AD – Acknowledge / Do Not Receipt • 3DD – Do Not Acknowledge / Do Not Receipt
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
            'gift_id'             => ['numeric'], // Enter 0 in this field to create a new pledge or the gift ID of an existing pledge.
            'donor_id'            => ['numeric'], // Enter the donor_id of the person for whom the pledge is being created/updated
            'gift_date'           => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'start_date'          => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'total'               => ['money'], // Enter either the total amount to be pledged (the sum of all the expected payment amounts) or enter 0 (zero) if the pledge amount is to be collected adinfinitum
            'bill'                => ['money'], // Enter the individual monthly/quarterly/annual billing amount
            'frequency'           => ['string', 30], // Enter one of: M (monthly), Q (quarterly), S (semi-annually), A (annually)
            'reminder'            => ['string', 1], // Sets the pledge reminder flag
            'gl_code'             => ['string', 30], // Note: If you are not setting a gl_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'solicit_code'        => ['string', 30], // Note: If you are not setting a solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'initial_payment'     => ['string', 1], // Set to ’Y’ for intial payment, otherwise ‘N’
            'sub_solicit_code'    => ['string', 30], // Note: If you are not setting a sub_solicit_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'writeoff_amount'     => ['money'], //
            'writeoff_date'       => ['datetime'], //
            'user_id'             => $this->appName,
            'campaign'            => ['string', 30], // Note: If you are not setting a campaign_code value in this field, set the field to null. Do not ever set this field to empty ('').
            'membership_type'     => ['string', 30], // Or NULL
            'membership_level'    => ['string', 30], // Or NULL
            'membership_enr_date' => ['datetime'], // Or NULL
            'membership_exp_date' => ['datetime'], // Or NULL
            'membership_link_ID'  => ['numeric'], // Or NULL
            'address_id'          => ['numeric'], // Or NULL
            'gift_narrative'      => ['string', 4000], // Or NULL
            'ty_letter_no'        => ['string', 30], // Or NULL
            'vault_id'            => ['numeric'], // This field must be populated from the Vault ID number returned by SafeSave for the pledge to be listed as active in the user interface.
            'receipt_delivery_g'  => ['string', 1], // ‘E’ for email, ‘B’ for both email and letter, ‘L’ for letter, ‘N’ for do not acknowledge or NULL
            'contact_id'          => ['numeric'], // Or NULL
            'acknowledgepref'     => ['string', 3],
            'currency'            => ['string', 3]
        ]));
    }

    /**
     * Saves fields to the DPCONTACT table. It will create a new or updated Contact record
     * for the specified donor_id.
     *
     * @param array $data
     * @return integer The contact_id of the created / updated record
     */
    public function dp_savecontact($data)
    {
        return $this->call('dp_savecontact', static::prepareParams($data, [
            'contact_id'     => ['numeric'], // Enter 0 to create a new record or the other_id record number of an existing dpcontact record
            'donor_id'       => ['numeric'], // Enter the Donor ID of the donor for whom the contact record is to be created or retrieved
            'activity_code'  => ['string', 30], // CODE value for the Activity Code field. See DPO Settings > Code Maintenance > Activity Code / Contact Screen. The required valuesc will be listed in the Code column of the resulting display.
            'mailing_code'   => ['string', 30], // CODE value for Mailing Code field
            'by_whom'        => ['string', 30], // CODE value for the By Whom/Contact Screen field in DPO Description value of selected code shows in the ‘Assigned To’ field of the contact record.
            'contact_date'   => ['date'], // Contact / Entry Date field in DPO
            'due_date'       => ['date'], // Due Date field in DPO. Set as 'MM/DD/YYYY' only. Setting time values here is not supported.
            'due_time'       => ['string', 20], // Time field in DPO. Enter as 'hh:mm xx' where xx is either AM or PM – e.g; '02:00 PM'.
            'completed_date' => ['date'], // Completed Date field in DPO. Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'comment'        => ['string', 4000], // Contact Notes field in DPO
            'document_path'  => ['string', 200], // Type a URL/File Path field in DPO
            'user_id'        => $this->appName,
        ]));
    }

    /**
     * Returns a predefined set of fields associated with all gifts given by the specified donor.
     *
     * @param array $data
     * @return integer other_id associated with the updated value This other_id value can now be
     *                 used as a @matching_id value to save additional fields associated with the
     *                 dpotherinfoudf table associated with this entry.
     */
    public function dp_saveotherinfo($data)
    {
        return $this->call('dp_saveotherinfo', static::prepareParams($data, [
            'other_id'   => ['numeric'], // Enter 0 to create a new record or the other_id record number of an existing dpotherinfo record
            'donor_id'   => ['numeric'], // Enter the donor_id for whom the record is to be created / updated.
            'other_date' => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'comments'   => ['string', 4000],
            'user_id'    => $this->appName,
        ]));
    }

    /**
     * Add or update secondary address values that appear in the DonorPerfect Online Address tab.
     * These secondary addresses are also referred to as Seasonal Addresses in the DPO Knowledge Base.
     *
     * @param array $data
     * @return integer the @address_id value of the newly created (or updated) DPADDRESS value.
     */
    public function dp_saveaddress($data)
    {
        return $this->call('dp_saveaddress', static::prepareParams($data, [
            'address_id'         => ['numeric'], // Enter 0 in this field to create a new address value or the address_id number of an existing address_id to update the existing value
            'donor_id'           => ['numeric'], // Specify the donor_id of the donor associated with this
            'opt_line'           => ['string', 100], // Enter a secondary name or company name if appropriate
            'address'            => ['string', 100], //
            'address2'           => ['string', 100], //
            'city'               => ['string', 50], //
            'state'              => ['string', 30], //
            'zip'                => ['string', 20], //
            'country'            => ['string', 30], //
            'address_type'       => ['string', 30], // Enter the CODE value associated with the address type
            'getmail'            => ['string', 1], // Enter 'Y' or 'N' to indicate whether the Receive Mail box will be checked and to indicate whether mail can be sent to this address.
            'user_id'            => $this->appName,
            'title'              => ['string', 50], // Enter a value to be stored in the Professional Title field.
            'first_name'         => ['string', 50], //
            'middle_name'        => ['string', 50], //
            'last_name'          => ['string', 75], //
            'suffix'             => ['string', 50], //
            'prof_title'         => ['string', 100], //
            'salutation'         => ['string', 130], // Enter desired salutation value (e.g.; 'Dear Bob')
            'seasonal_from_date' => ['string', 4], // Enter the 'from' date as MMYY – e.g; November 2017 would be represented as 1117
            'seasonal_to_date'   => ['string', 4], // Enter the 'to' date as MMYY
            'email'              => ['string', 75], //
            'home_phone'         => ['string', 40], //
            'business_phone'     => ['string', 40], //
            'fax_phone'          => ['string', 40], //
            'mobile_phone'       => ['string', 40], //
            'address3'           => ['string', 100], //
            'address4'           => ['string', 100], //
            'ukcountry'          => ['string', 100], //
            'org_rec'            => ['string', 1], // Enter 'Y' to check the Org Rec field (indicating an organizational record) or 'N' to leave it unchecked to indicate an individual record.
        ]));
    }

    /**
     * Saves a Donor’s extended information (User Defined Fields) — used to save changes
     * to the user-defined fields that are custom for each client and are not part of the
     * standard DPO system. This procedure will save a single parameter for a specified
     * User Defined Field (UDF).
     *
     * @param array $data
     * @return integer The donor_id associated with the updated value
     */
    public function dp_save_udf_xml($data)
    {
        return $this->call('dp_save_udf_xml', static::prepareParams($data, [
            'matching_id'  => ['numeric'], // Specify either an existing donor_id value if updating a donor record, a gift_id value if updating a gift record, a contact_id number if updating a contact record or an other_id value if updating a dpotherinfo table value (see dp_saveotherinfo). If you are updating a value in the DPADDRESSUDF, specify the @address_ID as the matching ID. Also, FYI, you can link the DPADDRESS.ADDRESS_ID=DPADDRESSUDF.ADDRESS_ID in any of your SELECT queries.
            'field_name'   => ['string', 20], //
            'data_type'    => ['string', 1], // C- Character, D-Date, N- numeric
            'char_value'   => ['string', 2000], // Null if not a Character field
            'date_value'   => ['date'], // Null if not a Date field. Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'number_value' => ['numeric'], // Null if not a Number field
            'user_id'      => $this->appName,
        ]));
    }

    /**
     * Add new code and description values to the DPCODES table. This would allow you
     * to add things like new GL Code, Solicitation, Campaign code and other code values
     * administered in the Code Maintenance screen of DPO. While this API call requires
     * the specification of many fields, you will typically only supply the Field Name,
     * Code, Description, & the Inactive field value
     *
     * @param array $data
     * @return integer 0
     */
    public function dp_savecode($data)
    {
        return $this->call('dp_savecode', static::prepareParams($data, [
            'field_name'        => ['string', 20], // Enter the name of an existing field type from the DPCODES table
            'code'              => ['string', 30], // Enter the new CODE value
            'description'       => ['string', 100], // Enter the description value that will appear in drop-down selection values
            'original_code'     => ['string', 20], // Enter NULL unless you are updating an existing code.  In that case, set this field to the current (before update) value of the CODE
            'code_date'         => ['date'], // Enter NULL
            'mcat_hi'           => ['money'], // Enter NULL
            'mcat_lo'           => ['money'], // Enter NULL
            'mcat_gl'           => ['string', 1], // Enter NULL
            'acct_num'          => ['string', 30], // Enter NULL
            'campaign'          => ['string', 30], // Enter NULL
            'solicit_code'      => ['string', 30], // Enter NULL
            'overwrite'         => null, // If you are creating a new code, set this field to NULL or 'N'.  If you are updating an existing code, set this to 'Y'.
            'inactive'          => ['string', 1], // Enter 'N' for an active code or 'Y' for an inactive code. Inactive codes are not offered in the user interface dropdown lists. Set @inactive='N' to indicate that this entry is Active and will appear in the appropriate drop-down field in the user interface.
            'client_id'         => null,
            'available_for_sol' => null,
            'user_id'           => $this->appName,
            'cashact'           => null,
            'membership_type'   => null,
            'leeway_days'       => null,
            'comments'          => ['string', 2000], // You may enter a comment of up to 2000 characters if you like.
            'begin_date'        => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'end_date'          => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'ty_prioritize'     => ['string', 1], // Enter NULL
            'ty_filter_id'      => null,
            'ty_gift_option'    => null,
            'ty_amount_option'  => null,
            'ty_from_amount'    => null,
            'ty_to_amount'      => null,
            'ty_alternate'      => null,
            'ty_priority'       => null,
        ]));
    }

    /**
     * Create a link between two donors. These links show in the Links tab of the DPO user
     * interface. Note that reciprocal links are created automatically. For example, if you
     * create a Friend link (FR) link with donor A as the donor_id value and donor B as the
     * donor_id2 value, you will automatically see the Friend link in the DPO user interface
     * if you look at the Links tab in either donor A or donor B. Please also note that DPO
     * will create appropriate reciprocal links in the same way it does via the user interface.
     * So, if you create a Parent link from donor A to donor B, then donor B will show donor
     * A as a Child link.
     *
     * @param array $data
     * @return integer link_id of the created link
     */
    public function dp_savelink($data)
    {
        return $this->call('dp_savelink', static::prepareParams($data, [
            'link_id'    => ['numeric'], // Enter zero (0) to create a new link entry of the link_id of an existing entry if you are updating an existing entry
            'donor_id'   => ['numeric'], // Enter the donor_id of the donor where the link is being created
            'donor_id2'  => ['numeric'], // Enter the donor_id of the OTHER donor involved in the link
            'link_code'  => ['string', 30], //  Enter the CODE value of the link per the Link Type values shown in the Code Maintenance screen of the DPO user interface
            'user_id'    => $this->appName,
        ]));
    }

    /**
     * Set a checkbox value. Checkbox fields in DPO are stored in the DPUSERMULTIVALUES
     * table and use a MATCHING_ID value as the index. The MATCHING_ID value corresponds
     * to the associated DONOR_ID. Note that even though a checkbox field may contain
     * many checkboxes, there will only be an entry present in DPUSERMULTIVALUES for
     * checkboxes that are set (checked).
     *
     * Recommendation: Consider using the mergemultivalues API call instead of this one.
     *
     * @param array $data
     * @return integer donor_id of the affected donor
     */
    public function dp_savemultivalue_xml($data)
    {
        return $this->call('dp_savemultivalue_xml', static::prepareParams($data, [
            'matching_id' => ['numeric'], // Specify the desired donor_id
            'field_name'  => ['string', 20], // Use the code value associated with the flag. For example, the ‘AL’, flag in this example had a description value of ‘Alumni’.
            'code'        => ['string', 30], // Enter the Code value of the checkbox entry you wish to set
            'user_id'     => $this->appName,
        ]));
    }

    /**
     * Set field values for an individual checkbox field. Any values not specified will be unset
     * (unchecked). This is the call to use instead of dp_deletemultivalues_xml if you ever need
     * to unset (uncheck) a checkbox field because it allows you to modify a single checkbox field
     * instead of having to retrieve all checkbox fields for the screen tab and re-set them.
     *
     * Depending on what you need to do, you can probably also use this instead of the
     * dp_savemultivalues_xml API call as well – as it allows you to set (check) checkboxes as
     * well as un-set them. Also, please note that the field specifications are slightly different
     * than the usual when you are entering the command string – e.g.; matchingid is correct.
     * matching_id is incorrect.
     *
     * @param array $data
     * @return array|null
     */
    public function mergemultivalues($data)
    {
        return $this->call('mergemultivalues', static::prepareParams($data, [
            'matchingid'  => ['numeric'], //  Specify the desired donor_id
            'fieldname'   => ['string', 20], // Enter the name of the checkbox field name.
            'valuestring' => ['string', 20], // Enter any CODE values to be set. Separate with commas. Any code values not specified will be unset (unchecked).
            'debug'       => ['numeric'], // Specification of this field is optional but if you want to return the list of checkbox fields and the values in them after running this command then add debug=1 as a parameter to this API call. If a code was previously set but was not specified in your mergemultivalues API call then it will show as a DeletedCode value. If a value was not previously set but was specified in your API call, then it will show as an InsertedCode.
        ]));
    }

    /**
     * Deletes ALL checked checkbox values on the specified screen tab (e.g.; Main, Gift,
     * Pledge,Bio, Other) for the specified donor. Checkbox fields in DPO are stored in
     * the DPUSERMULTIVALUES table and use a MATCHING_ID value as the index. The MATCHING_ID
     * value corresponds to the associated DONOR_ID. Note that even though a checkbox field
     * may contain many checkboxes, there will only be an entry present in DPUSERMULTIVALUES
     * for checkboxes that are set (checked). We recommend that before using this API call,
     * you use a dynamic SELECT statement to retrieve all set (checked) values for the
     * specified donor. This will allow you to follow the delete API call with
     * dp_savemultivalues_xml API calls to reset/check the checkbox fields you did not want
     * deleted.
     *
     * This API call removes ALL checked values from the specified DPO screen tab.
     *
     * Recommendation: Consider using the mergemultivalues API call instead of this one.
     *
     * @param array $data
     * @return integer donor_id of the affected donor
     */
    public function dp_deletemultivalues_xml($data)
    {
        return $this->call('dp_deletemultivalues_xml', static::prepareParams($data, [
            'matching_id' => ['numeric'], // Specify the desired donor_id
            'table_name'  => ['string', 20], // Enter the name of the DPO screen tab (i.e. MAIN, GIFT, PLEDGE, BIO, OTHER) where all checked fields are to be deleted
            'user_id'     => $this->appName,
        ]));
    }

    /**
     * Set flags as shown in the top section of the Main tab. The Flags field is on the
     * Main tab in the DPO user interface. Flags must have been previously created in
     * Settings > Code Maintenance and the value you set corresponds to the Code value,
     * not the description value.
     *
     * @param array $data
     * @return integer donor_id of the affected donor
     */
    public function dp_saveflag_xml($data)
    {
        return $this->call('dp_saveflag_xml', static::prepareParams($data, [
            'donor_id' => ['numeric'], // Specify either a donor_id value if updating a donor record, a gift_id value if updating a gift record or an other_id value if updating a dpotherinfo table value (see dp_saveotherinfo)
            'flag'     => ['string', 30], // Use the code value associated with the flag. For example, the ‘AL’, flag in this example had a description value of ‘Alumni’.
            'user_id'  => $this->appName,
        ]));
    }

    /**
     * Removes (deletes) all flags for the specified donor. Flags are shown on the
     * main donor screen in DPO. It is not currently possible to delete individual
     * flags for a specified donor. This command deletes all flags set for the specified
     * donor_id. To view the flags set for a specified donor, use a SELECT query to
     * retrieve a list of all set flags for the specified donor. You will use this
     * list of set flags to re-set all flags you did not want un-set for the specified
     * donor: SELECT * FROM DPFLAGS WHERE DONOR_ID={desired donor_id}
     *
     * See dp_saveflag_xml for information on setting flags
     *
     * @param array $data
     * @return integer donor_id of the affected donor
     */
    public function dp_delflags_xml($data)
    {
        return $this->call('dp_delflags_xml', static::prepareParams($data, [
            'donor_id' => ['numeric'], // Specify the donor_id of the donor for whom the flags (all of them) are to be deleted
            'user_id'  => $this->appName,
        ]));
    }

    /**
     * Retrieves either a list of All tributes or All Active tributes
     * (<field id="ActiveFlg" value="True" name="ActiveFlg")
     *
     * @param array $data
     * @return array
     */
    public function dp_tribAnon_MyTribSummary($data)
    {
        return $this->call('dp_tribAnon_MyTribSummary', static::prepareParams($data, [
            'ShowAllRecords' => ['numeric'], // If set to 1, retrieves all tributes If set to 0, retrieves all ACTIVE tributes but does not include inactive tributes – i.e.; tributes where the ActiveFlg='False'
            'userId'  => null,
        ]));
    }

    /**
     * Search for a tribute by the tribute name and either include or exclude
     * inactive tributes. If you already have a tribute that has the same type
     * (CodeDescription) as one you are trying to create, you can use the matching
     * DPCode_ID value.
     *
     * @param array $data
     * @return array
     */
    public function dp_tribAnon_Search($data)
    {
        return $this->call('dp_tribAnon_Search', static::prepareParams($data, [
            'Keywords'        => ['string', 200], // Enter all or part of the tribute name to retrieve data on each matching tribute.
            'IncludeInactive' => ['numeric'], // Enter 1 to include inactive tributes (ActiveFlg=False) or enter 0 to exclude these.
        ]));
    }

    /**
     * Create a new tribute in DonorPerfect Online (DPO)
     *
     * @param array $data
     * @return null
     */
    public function dp_tribAnon_Create($data)
    {
        return $this->call('dp_tribAnon_Create', static::prepareParams($data, [
            'Name'         => ['string', 200], // Specify the name that will be used for the tribute
            'DPCodeID'     => ['numeric'], // This is the numeric code_ID value that is associated with the tribute type. The standard values are M (In Memory Of) and H (In Honor Of) but you will not be specifying the letter value here but rather the numeric value of the code_ID. You can get the required @code_id value with this SQL SELECT query: SELECT CODE, CODE_ID, DESCRIPTION FROM DPCODES WHERE FIELD_NAME = 'MEMORY_HONOR' Run the query and record the CODE_ID values. They will not change for your DonorPerfect system. If you are connecting to multiple DonorPerfect systems, you will need to run this once for each system you are connecting to and store the values.
            'ActiveFlg'    => ['bool'], // Enter 1 here to make the tribute active or 0 to make it inactive.
            'UserCreateDt' => ['date'], // Enter the current date in this format: mm/dd/yyyy and place single quotes around the date. Entry of time values is NOT supported.
            'Recipients'   => ['string'], // Enter either the donor_id of a single recipient OR multiple donor_id values separated by the pipe symbol and wrapped in single quotes. See examples below. The second example shows four donor ID numbers (11101, 22202, 33303, 44404) being assigned as Recipients and then in the Returns section below that, you can see the names of the donors who correspond to those donor ID numbers. Note: It is possible to create a tribute without specifying any @recipients by omitting this parameter. Any recipients specified here will be notified of all gifts to the tribute from the time that their recipient donor_id is added. (i.e.; if a new recipient is added later on, it won't notify them of gifts received before they are added)
        ]));
    }

    /**
     * Create the association between a gift and a tribute by specifying the
     * GIFT_ID and the TributeID_List. Once you have associated a tribute to
     * a gift, the tribute will show in the Tribute Details section of the
     * gift screen.
     *
     * @param array $data
     * @return integer The TributeID
     */
    public function dp_tribAnon_AssocTribsToGift($data)
    {
        return $this->call('dp_tribAnon_AssocTribsToGift', static::prepareParams($data, [
            'Gift_ID'        => ['numeric'], // Specify the GIFT_ID of the gift that will be associated with the specified tribute
            'TributeID_List' => ['string'], // Enter the TributeID of the tribute. You can optionally enter a comma separated list of tribute ID numbers – e.g.; 9, 12, 33 Tribute IDs are included in the data retrieved in the dp_tribAnon_MyTribSummary API call.
        ]));
    }

    /**
     * Add a new recipient notification for a particular gift (This gift):
     * When you have a gift that already has tribute(s) applied to it, the
     * user interface shows an option to Add a Recipient. The resulting window
     * gives you the opportunity to send a notification to an additional
     * recipient, and specifically, for just this gift. This API call supports
     * that functionality.
     *
     * To locate the recipient in the DPO user interface: 1. Go to Tributes
     * under the cog icon (top right hand corner of user interface) 2. Search
     * for and select the tribute by its Tribute ID number (above) 3. You
     * should see the recipient donor under the 'Send Notifications To' text
     * The dp_tribNotif_Save API call should be run after the dp_tribAnon_SaveTribRecipient
     * API call to make sure that the actual notification record is created
     * for the recipient of the notification.
     *
     * @param array $data
     * @return null Unsure what this returns, undocumented
     */
    public function dp_tribAnon_SaveTribRecipient($data)
    {
        return $this->call('dp_tribAnon_AssocTribsToGift', static::prepareParams($data, [
            'DonorId'   => ['numeric'], // The Donor ID number of the new tribute notification recipient. Example, if you are notifying Susan about Frank's gift, you would specify Susan's Donor_ID number here
            'TributeID' => ['numeric'], // The ID number of the tribute
            'GiftID'    => ['numeric'], // The Gift ID number of the gift this donor will be notified about. Per example above, you would specify the Gift_ID number of Fred's gift here.
            'Level'     => 'L',
        ]));
    }

    /**
     * Create a tribute gift notification record for a person who is to be
     * notified of a tribute. The tribute gift notification records added with
     * this API call are for the notification recipient for this gift only
     *
     * The dp_tribNotif_Save API call should be run after the dp_tribAnon_SaveTribRecipient
     * API call to make sure that the actual notification record is created for
     * the recipient of the notification.
     *
     * Tribute gift notification records will show in the Linked Gift screen of
     * the original gift (i.e.; the gift_id specified in the @glink field in a
     * table called Linked Gifts. This table will include all notification gifts
     * for all tributes linked to this gift.
     *
     * @param array $data
     * @return integer The TributeID
     */
    public function dp_tribNotif_Save($data)
    {
        return $this->call('dp_tribNotif_Save', static::prepareParams($data, [
            'Gift_ID'             => ['numeric'], // Enter 0 to create a new tribute gift notification record or the gift_id number of an existing tribute gift notification record if you are updating one.
            'Donor_Id'            => ['numeric'], // The Donor ID number of the new notification record recipient. Please note that this may be a different donor than the person who gave the original tribute gift.
            'glink'               => ['numeric'], // The Gift ID number of the gift this donor will be notified about
            'tlink'               => ['numeric'], // The Tribute ID number of the tribute
            'smount'              => ['money'], // Enter the amount of the gift
            'total'               => ['money'], // Enter the amount of the gift
            'bill'                => ['money'], // Enter 0.00
            'start_date'          => null, // Date. Enter null
            'frequency'           => null, // Varchar. Enter null
            'gift_type'           => 'SN', // Enter 'SN'
            'record_type'         => 'N', // Enter 'N' to indicate this is a notification record
            'gl_code'             => ['string'], // Enter same value that was set for the original gift
            'solicit_code'        => ['string'], // Enter same value that was set for the original gift
            'sub_solicit_code'    => ['string'], // Enter same value that was set for the original gift
            'campaign'            => ['string'], // Enter same value that was set for the original gift
            'ty_letter_no'        => 'NT', // Varchar(30). Enter 'NT' for this field.
            'fmv'                 => ['money'], // Enter same value that was set for the original gift
            'reference'           => ['string'], // Enter SafeSave transaction record or null
            'gfname'              => null, // Varchar(50) null
            'glname'              => null, // Varchar(75) null
            'gift_narrative'      => ['string', 4000], //(4000) If desired, enter gift narrative text
            'membership_type'     => null, // Varchar. Null
            'membership_level'    => null, // Varchar. Null
            'membership_enr_date' => null, // Date. Null
            'membership_exp_date' => null, // Date. Null
            'address_id'          => ['numeric'], // Enter 0 unless the notification letter is to go to an address other than the one shown on the Main screen.
            'user_id'             => $this->appName,
        ]));
    }

    /**
     * Update the list of recipients for all gifts that are associated with a
     * particular tribute. This API call is different that the dp_tribAnon_SaveTribRecipient
     * and dp_tribNotif_Save API calls which add a recipient to a tribute for one specified
     * gift. It is API equivalent to the Add Recipient link in the Edit Tribute screen
     *
     * IMPORTANT: You need to first retrieve info on the existing tribute @recipients as you
     * must include these in the dp_tribAnon_Update call otherwise they will no longer be
     * associated with the tribute.
     *
     * It is not necessary to follow this API call with the dp_tribNotif_Save API call.
     *
     * @param array $data
     * @return array
     */
    public function dp_tribAnon_Update($data)
    {
        return $this->call('dp_tribAnon_Update', static::prepareParams($data, [
            'TributeID'    => ['numeric'], // Enter the ID number of the tribute you are updating
            'name'         => ['string', 200], // Enter the existing name of the tribute to be updated. See Notes section below for example of a SELECT statement that will give you this info.
            'dpcode_id'    => ['numeric'], // This is the numeric code_ID value that is associated with the tribute type. See Notes section below for example of a SELECT statement that will give you this info.
            'ActiveFlg'    => ['bool'], // Set as 1 for True (active) or 0 for False (inactive)
            'UserCreateDt' => ['date'], // Enter existing user create date in this format: 'MM/DD/YYYY'
            'recipients'   => ['array'], // Enter the list of all existing and any new recipients using the pipe | symbol as a delimiter. ALSO, prefix the list with capital letter N and also wrap the recipients list in single quotes. Example: @recipients=N'105|43256|323387|137'
        ]));
    }

    /**
     * Insert DPO Payment Method values. This table is used on systems with the EFT Transactions
     * feature enabled. This procedure will save a single parameter for a specified User Defined
     * Field (UDF).
     *
     * This table would normally only be populated from an ecommerce API where the DPO system has
     * EFT Transactions enabled.
     *
     * @param array $data
     * @return integer DpPaymentMethodID
     */
    public function dp_PaymentMethod_Insert($data)
    {
        return $this->call('dp_PaymentMethod_Insert', static::prepareParams($data, [
            'CustomerVaultID'            => ['string', 55], // Enter -0 to create a new Customer Vault ID record
            'donor_id'                   => ['numeric'], //
            'IsDefault'                  => ['bool'], // Enter 1 if this is will be the default EFT payment method. Note anything other than 1 (i.e. 0 or NULL fails and not sure why)
            'AccountType'                => ['string', 256], // e.g. ‘Visa’
            'dpPaymentMethodTypeID'      => ['string', 20], // e.g.; ‘creditcard’
            'CardNumberLastFour'         => ['string', 16], // e.g.; ‘4xxxxxxxxxxx1111
            'CardExpirationDate'         => ['string', 10], // e.g.; ‘0810’
            'BankAccountNumberLastFour'  => ['string', 50], //
            'NameOnAccount'              => ['string', 256], //
            'CreatedDate'                => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'ModifiedDate'               => ['date'], // Set as 'MM/DD/YYYY' only. Setting time values is not supported.
            'import_id'                  => ['numeric'], //
            'created_by'                 => ['string', 20], //
            'modified_by'                => ['string', 20], //
            'selected_currency'          => ['string', 3], // e.g 'USD', 'CAD', per default currency used by the DonorPerfect client
        ]));
    }
}
