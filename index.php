<?php

require_once('./Moneybird/Api.php');
date_default_timezone_set('Europe/Amsterdam');

class Connector
{
    const RAW_DATA_FILE = 'rawdata.log';
    const LOG_FILE = 'logfile.log';
    const DUMP_DATA = false;

    const SUBDOMAIN = 'subdomain';
    const USERNAME = 'username@example.com';
    const PASSWORD = 'xxxxx';

    const TAX_RATE_ID_TWENTYONE_PERCENT = 3;
    const TAX_RATE_ID_SIX_PERCENT = 2;
    const TAX_RATE_ID_ZERO_PERCENT = 1;

    const NOTIFY_EMAIL = 'notify@example.com';

    public function __construct($data)
    {
        $this->_fields = array(
            'timestamp',
            'voornaam',
            'achternaam',
            'e-mailadres',
            'straatnaam',
            'postcode',
            'huisnummer',
            'plaats',
            'tenaamstellingrekeningnummer',
            'mobieltelefoonnummer',
            'ibanrekeningnummer',
            'noodcontact'
        );

        $this->monthlyPrice = 30;

        $this->setData($data);

        if(self::DUMP_DATA)
        {
            $this->_log(print_r($data, true) . PHP_EOL, self::RAW_DATA_FILE);
        }

        $this->mbapi = new MoneybirdApi(self::SUBDOMAIN,self::USERNAME,self::PASSWORD);

    }

    private function setData($data)
    {
        if(!isset($this->_data))
        {
            $newData = array();
            foreach($this->_fields as $key)
            {
                if(isset($data['gsx$' . $key])) {
                    $newData[$key] = $data['gsx$' . $key];
                }
            }
            $this->_data = $newData;
        }
    }

    public function getData()
    {
        return $this->_data;
    }

    public function createRecurringInvoice()
    {
        $data = $this->getData();
        if(count($data) == 0) return null;
        $contact = $this->getContactByEmail($data['e-mailadres']);

        if($contact==false) {
            try {
                $contact = $this->saveContact($data);
            } catch(Exception $e) {
                $exceptionMessage = $e->getMessage();
            }
        }

        if($contact==false || $contact->id == false) {
            $this->_log('Could not find/save contact with email address ' . $data['e-mailadres'] . '; ' . $exceptionMessage, null, true);
            return false;
        }

        $recurringTemplates = $this->mbapi->getRecurringTemplates();
        $numberOfRecurringInvoices = 0;
        foreach($recurringTemplates as $template) {
            if($template->{'contact_id'} == $contact->id) {
                $numberOfRecurringInvoices++;
            }
        }

        $exceptionMessages = array();

        if($numberOfRecurringInvoices === 0) {
            /* Calculate how many days remain in this month */
            $daysRemaining = date('t') - date('d');
            /* Do not send an invoice for 0 or 1 day, but start from 2 days */
            if($daysRemaining >= 2) {
                $priceThisMonth = ($this->monthlyPrice / date('t')) * $daysRemaining;
                try {
                    $onetimeInvoice = $this->saveOnetimeInvoice($contact, $data, $priceThisMonth, $daysRemaining);
                } catch(Exception $e) {
                    $exceptionMessages[] = $e->getMessage();
                }
            }

            try {
                $recurringInvoice = $this->saveRecurringInvoice($contact, $data);
            } catch(Exception $e) {
                $exceptionMessages[] = $e->getMessage();
            }
        } else {
            $this->_log('Recurring invoice already exists for contact with email address ' . $data['e-mailadres'], null, true);
            return false;
        }

        if($recurringInvoice == false || $recurringInvoice->id == false || $onetimeInvoice || $onetimeInvoice->id == false) {
            $this->_log('Could not find/save recurring invoice for contact with email address ' . $data['e-mailadres'] . '; ' . implode(PHP_EOL, $exceptionMessages), null, true);
            return false;
        }

        return true;

    }

    private function _log($message, $outputFile = null, $notify = false)
    {
        if($outputFile == null)
        {
            $outputFile = self::LOG_FILE;
        }

        if($message) {
            file_put_contents($outputFile, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
        }

        if($notify) {
            mail(self::NOTIFY_EMAIL, 'Maakplek Moneybird Connector error', $message);
        }
    }

    public function updateContactInfo()
    {
        $data = $this->getData();
        $contact = $this->getContactByEmail($data['e-mailadres']);
        if($contact->id) {
            try {
                $result = $this->saveContact($data, $contact);
                return $result;
            } catch(Exception $e) {
                $exceptionMessage = $e->getMessage();
            }
        }
        $this->_log('Could not find/save contact with email address ' . $data['e-mailadres'] . '; ' . $exceptionMessage, null, true);
        return false;
    }

    private function getContactByEmail($email) {
        if(empty($email)) {
            return $this->mbapi->getContacts();
        } else {
            $contacts = $this->mbapi->getContacts();
            foreach($contacts as $contact) {
                if($contact->email==$email) {
                    return $contact;
                }
            }
        }
        return false;
    }

    private function saveContact($contactData, $contact = null)
    {
        if($contact == null) {
            $contact = new MoneybirdContact;
        }
        $contact->firstname = ucwords($contactData['voornaam']);
        if(stripos($contact->lastname,' ')===false) {
            $contact->lastname = ucwords($contactData['achternaam']);
        } else {
            $contact->lastname = $contactData['achternaam'];
        }
        $contact->attention = ucwords($contactData['tenaamstellingrekeningnummer']);
        $contact->address1 = ucwords($contactData['straatnaam']) . $contactData['huisnummer'];
        $contact->address2 = '';
        $contact->zipcode = strtoupper($contactData['postcode']);
        $contact->city = ucwords($contactData['plaats']);
        $contact->country = 'Nederland';
        $contact->email = trim(strtolower($contactData['e-mailadres']));
        $contact->phone = trim($contactData['mobieltelefoonnummer']);
        $contact->{'bank_account'} = strtoupper($contactData['ibanrekeningnummer']);

        $contact = $this->mbapi->saveContact($contact);

        if($contact->id) {
            return $contact;
        } else {
            return false;
        }
    }

    private function saveRecurringInvoice($contact = null, $data = null)
    {
        if(!$contact || !$data || !is_array($data)) {
            return false;
        }

        $recurringInvoice = new MoneybirdRecurringTemplate;
        $recurringInvoice->{'contact-id'} = $contact->id;

        // Once per month
        $recurringInvoice->{'frequency-type'} = $recurringInvoice::FREQUENCY_MONTH;
        $recurringInvoice->frequency = 1;

        $recurringInvoice->{'send-invoice'} = true;
        /* First of next month (the remainder of the current month is billed through the onetime invoice) */
        $recurringInvoice->{'start-date'} = date('Y-m-d', mktime(0, 0, 0, date('m')+1, 1, date('Y')));

        $line = new MoneybirdRecurringTemplateLine;
        $line->amount = '1 maand';
        $line->description = 'Leden contributie maakplek {date.month} {date.year}';
        $line->{'tax-rate-id'} = self::TAX_RATE_ID_ZERO_PERCENT;
        $line->price = $this->monthlyPrice;

        $lines = array($line);
        $recurringInvoice->{'details'} = $lines;

        $recurringInvoice = $this->mbapi->saveRecurringTemplate($recurringInvoice);

        if($recurringInvoice->id) {
            return $recurringInvoice;
        } else {
            return false;
        }
    }

    private function saveOnetimeInvoice($contact = null, $data = null, $price = 0, $daysRemaining = 0)
    {
        if(!$contact || !$data || !is_array($data)) {
            return false;
        }

        $invoice = new MoneybirdInvoice;
        $invoice->setContact($contact);

        $line = new MoneybirdInvoiceLine($invoice);
        $line->amount = '1';
        $line->description = 'Deel leden contributie maakplek ' . $daysRemaining . ' resterende dagen in de maand ' . date('F');
        $line->{'tax-rate-id'} = self::TAX_RATE_ID_ZERO_PERCENT;
        $line->price = $price;

        $lines = array($line);
        $invoice->{'details'} = $lines;

        $invoice = $this->mbapi->saveInvoice($invoice);

        $MoneybirdInvoiceSendInformation = new MoneybirdInvoiceSendInformation(
            'hand',
            $data['e-mailadres'],
            null
        );
        $this->mbapi->sendInvoice($invoice, $MoneybirdInvoiceSendInformation);

        if($invoice->id) {
            return $invoice;
        } else {
            return false;
        }
    }
}

$test = false;
//$_GET['update'] = 'true';

if($test) {
    $data = array(
        'gsx$voornaam' => 'Voornaam',
        'gsx$timestamp' => '8/26/2015 11:17:36',
        'gsx$plaats' => 'Woonplaats',
        'gsx$achternaam' => 'Achternaam',
        'gsx$noodcontact' => 'Nood Contact (0612345678)',
        'gsx$e-mailadres' => 'voornaam@example.com',
        'gsx$straatnaam' => 'Straatnaam',
        'gsx$huisnummer' => '1A',
        'gsx$tenaamstellingrekeningnummer' => 'D. Duck',
        'gsx$mobieltelefoonnummer' => '0612345678',
        'gsx$ibanrekeningnummer' => 'NL12MAAK012345678',
        'gsx$postcode' => '1234AB',
        'gsx$minibaasje' => 'Nee'
    );
} else {
    $data = json_decode(file_get_contents('php://input'),true);
    $data = array_pop($data);
}

$connector = new Connector($data);
if(isset($_GET['update']) && $_GET['update'] == 'true') {
    $result = $connector->updateContactInfo();
} else {
    $result = $connector->createRecurringInvoice();
}
if($result === true) {
    echo 'Success';
} elseif($result === false) {
    echo 'Error';
}


