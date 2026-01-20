<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

class MQTTSyncClientDevice extends IPSModule
{
    private const GUID_MQTT_SEND = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_PACKET_PUBLISH = 3;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}');
        $this->RegisterPropertyString('MQTTTopic', '');
        $this->RegisterPropertyString('GroupTopic', '');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->ConnectParent('{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}');

        $GroupTopic = $this->ReadPropertyString('GroupTopic');
        $MQTTTopic = $this->ReadPropertyString('MQTTTopic');
        $this->SetReceiveDataFilter('.*mqttsync/' . $GroupTopic . '/' . $MQTTTopic . '".*');

        $Payload = [];
        $Payload['config'] = 'variables';
        $Topic = 'mqttsync/' . $this->ReadPropertyString('GroupTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/get';
        if ($this->HasActiveParent()) {
            $this->sendMQTTCommand($Topic, $Payload);
        }
    }

    public function ReceiveData($JSONString): string
    {
        $this->SendDebug('ReceiveData JSON', $JSONString, 0);
        $Data = json_decode($JSONString);

        // Fix für IPS 6.3 encoding
        $this->ensureUtf8Payload($Data);

        if (!property_exists($Data, 'Topic')) {
            return '';
        }

        $Variablen = json_decode($Data->Payload, true, 512, JSON_THROW_ON_ERROR);

        foreach ($Variablen as $Variable) {
            $this->processReceivedVariable($Variable);
        }
        return '';
    }

    private function processReceivedVariable(array $Variable): void
    {
        $ident = ($Variable['ObjectIdent'] !== '') ? $Variable['ObjectIdent'] : $Variable['ID'];
        $name = (string) $Variable['Name'];

        $profileOrPresentation = $this->determineProfileOrPresentation($Variable);

        $this->MaintainVariable($ident, $name, $Variable['VariableTyp'], $profileOrPresentation, 0, true);

        if ((isset($Variable['VariableAction']) && $Variable['VariableAction'] !== 0) || (isset($Variable['VariableCustomAction']) && $Variable['VariableCustomAction'] > 1)) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }

        $this->SendDebug('Value for ' . $ident . ':', $Variable['Value'], 0);
        $this->SetValue($ident, $Variable['Value']);
    }

    private function determineProfileOrPresentation (array $Variable)
    {
        // Priorität 1: Custom Presentation
        if (isset($Variable['VariableCustomPresentation']) && $Variable['VariableCustomPresentation'] !== '') {
            return (array) $Variable['VariableCustomPresentation'];
        }

        // Priorität 2: Standard Presentation
        if (isset($Variable['VariablePresentation']) && $Variable['VariablePresentation'] !== '') {
            return (array) $Variable['VariablePresentation'];
        }

        // Priorität 3: Custom Profil oder Standard Profil
        if ($Variable['VariableCustomProfile'] !== '') {
            return (string) $Variable['VariableCustomProfile'];
        }

        return (string) $Variable['VariableProfile'];
    }

    private function ensureUtf8Payload(object $Data): void
    {
        // Für MQTT Fix in IPS Version 6.3 (Kernel Date > 12.12.2022)
        if (IPS_GetKernelDate() > 1670886000) {
            $Data->Payload = utf8_decode($Data->Payload);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        $Payload = [];
        $Payload['ObjectIdent'] = $Ident;
        $Payload['Value'] = $Value;
        $Topic = 'mqttsync/' . $this->ReadPropertyString('GroupTopic') . '/' . $this->ReadPropertyString('MQTTTopic') . '/set';
        $this->sendMQTTCommand($Topic, $Payload);
    }

    protected function sendMQTTCommand($topic, $payload, $retain = false)
    {
        $data = [
            'DataID'           => self::GUID_MQTT_SEND,
            'PacketType'       => self::MQTT_PACKET_PUBLISH,
            'QualityOfService' => 0,
            'Retain'           => $retain,
            'Topic'            => $topic,
            'Payload'          => json_encode($payload),
        ];

        $dataJSON = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->SendDebug(__FUNCTION__ , 'dataJSON: ' . $dataJSON, 0);
        $this->SendDataToParent($dataJSON);
    }
}
