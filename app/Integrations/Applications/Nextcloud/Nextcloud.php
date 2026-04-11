<?php

namespace App\Integrations\Applications\Nextcloud;

use App\Integrations\Applications\Application;
use Illuminate\Support\Facades\Http;

class Nextcloud extends Application
{
    public $name = 'Nextcloud';

    public $expected_response = 'xml';

    private $active_group_folder_id = null;

    private $active_group_folder = null;

    protected $base_path = '/ocs/v1.php/cloud';

    public function basePath()
    {
        return $this->app_instance->address().$this->base_path;
    }

    public function response_content()
    {
        return $this->response()['content'];
    }

    public function headers()
    {
        return [
            'OCS-APIRequest' => 'true',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }

    public function parseResponse($response)
    {
        if (! $this->hasError() && $this->expected_response == 'xml') {
            libxml_use_internal_errors(true); // Prevents errors from non-xml responses
            $body = (object) simplexml_load_string($response);

            // Check for XML formatting errors
            if (libxml_get_errors()) {
                foreach (libxml_get_errors() as $xml_error) {
                    $xml_errors[] = $xml_error->message;
                }

                $this->setError(json_encode($xml_errors), 'response_format', false);
            }

            if (is_object($body) && property_exists($body, 'meta')) {
                if ($body->meta->status == 'ok') {
                    $this->setResponse($body->data);
                } elseif ($body->meta->status == 'failure') {
                    $this->setError($body->meta->message, $body->meta->statuscode, true);
                }
            } else {
                $this->setError('unknown error', 'unknown');
            }
        }
    }

    public function auth()
    {
        $support_user = $this->support_user();

        return [
            $support_user,
            $this->app_instance->api_password(),
        ];
    }

    public function testing_fakes()
    {
        Http::fake([
            'https://demo-nextcloud.local.dev/apps/groupfolders/folders' => '
<?xml version="1.0"?>
<ocs>
 <meta>
  <status>ok</status>
  <statuscode>100</statuscode>
  <message>OK</message>
  <totalitems></totalitems>
  <itemsperpage></itemsperpage>
 </meta>
 <data>
  <element>
   <id>1</id>
   <mount_point>Fake Group Folder</mount_point>
   <quota>21474836480</quota>
   <acl>1</acl>
   <size>609434268</size>
   <groups>
    <element group_id="Fake Group Folder" permissions="31" display-name="Fake" type="group"/>
   </groups>
   <manage>
    <element>
     <type>user</type>
     <id>testing1</id>
     <displayname>Testing 1</displayname>
    </element>
   </manage>
  </element>
 </data>
</ocs>
',
            'https://demo-nextcloud.local.dev/ocs/v1.php/cloud/users/testing1' => [],
            'https://demo-nextcloud.local.dev/ocs/v1.php/cloud/users/testing2' => [],
        ]);
    }

    public function array_to_xml($data)
    {
        $xml_data = new \SimpleXMLElement('<?xml version="1.0"?><data></data>');
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = 'item'.$key; // dealing with <0/>..<n/> issues
                }
                $subnode = $xml_data->addChild($key);
                $this->array_to_xml($value, $subnode);
            } else {
                $xml_data->addChild("$key", htmlspecialchars("$value"));
            }
        }

        return $xml_data;
    }
}
