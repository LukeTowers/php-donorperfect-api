# About

A simple PHP wrapper around the [DonorPerfect API](https://uploads.softerware.com/doclib/DP/Manuals/DPO_SUP_Manual_XML_API_Documentation.pdf). Currently targets DonorPerfect Online Version 2020.12. See [https://api.warrenbti.com/](https://api.warrenbti.com/) for the online API documentation.

## Installation

Install via [Composer](https://getcomposer.org/) by running `composer require luketowers/php-donorperfect-api` in your project directory.

## Usage

In order to use this wrapper library you will need to provide credentials to access DonorPerfect's API.

You will either need a user login and password for the account you are trying to access or an API key issued by emailing DonorPerfect support.

## Examples

### Initialize API:

```php
use LukeTowers\DonorPerfectPHP\DonorPerfect;

// Initialize the client with an API key and app name (max 20 characters)
$api = new DonorPerfect('my_api_key_here', 'NameOfMyApp');

// Initialize the client with a user login and password and app name (max 20 characters)
$api = new DonorPerfect(['login' => 'MyUsername', 'pass' => 'MyPassword'], 'NameOfMyApp');
```

### Call one of the predefined DP actions

```php
// Call one of the predefined DP actions
$result = $api->dp_donorsearch(['donor_id' => 1]);

// Call a predefined DP action not yet implemented in this library
$result = $api->call('dp_actionname', DonorPerfect::prepareParams(['donor_id' => 1], $arrayOfParamConfigsExpected));
```

### Run a custom MS SQL statement through the API

```php
$pageStart = 1;
$pageEnd = 500;

// Run a custom MS SQL statement through the API
$result = $api->callSql("
    SELECT
        *
    FROM (
        SELECT
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
    ) AS tmp
    WHERE tmp.row_number BETWEEN {$pageStart} AND {$pageEnd}
");
```
