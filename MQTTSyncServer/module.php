<?php

declare(strict_types=1);

class MQTTSyncServer extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->RegisterPropertyString('GroupTopic', 'symcon');
        $this->RegisterPropertyBoolean('Retain', false);
        $this->RegisterPropertyString('Devices', '[]');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');

        $MQTTTopic = $this->ReadPropertyString('GroupTopic');
        $this->SetReceiveDataFilter('.*mqttsync/' . $MQTTTopic . '.*');

        $activeMessages = [];

        $DevicesJSON = $this->ReadPropertyString('Devices');
        if ($DevicesJSON != '') {
            $Devices = json_decode($DevicesJSON);
            foreach ($Devices as $key=>$Device) {
                $this->SendDebug(__FUNCTION__ . 'Devices', $Device->ObjectID . ' ' . $Device->MQTTTopic, 0);
                $Instanz = IPS_GetObject($Device->ObjectID);
                switch ($Instanz['ObjectType']) {
                    case 1: //Object
                        foreach ($Instanz['ChildrenIDs'] as $Children) {
                            if (IPS_VariableExists($Children)) {
                                $this->RegisterMessage($Children, VM_UPDATE);
                                $this->RegisterReference($Children);
                                array_push($activeMessages, $Children);
                            }
                        }
                        break;
                    case 2: //Variable
                        if (IPS_VariableExists($Instanz['ObjectID'])) {
                            $this->RegisterMessage($Instanz['ObjectID'], VM_UPDATE);
                            $this->RegisterReference($Instanz['ObjectID']);
                            array_push($activeMessages, $Instanz['ObjectID']);
                        }
                        break;
                    case 3:
                        $this->SendDebug(__FUNCTION__, 'Script', 0);
                        break;
                }
            }
            //Unregesiter Variablen - welche nicht mehr in der Liste vorhanden sind
            $MessageList = $this->GetMessageList();
            foreach ($MessageList as $key=>$Device) {
                if (!in_array($key, $activeMessages)) {
                    $this->UnregisterMessage($key, VM_UPDATE);
                    $this->UnregisterReference($key);
                }
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case VM_UPDATE:

                if ($Data[1]) { // HasDiff
                    $Topic = '';
                    $Instanz = null;
                    $Object = IPS_GetObject($SenderID);

                    if ($this->isInstance($SenderID)) {
                        $Topic = $this->TopicFromList($Object['ParentID']);
                        $PObject = IPS_GetObject($Object['ParentID']);
                        $i = 0;
                        foreach ($PObject['ChildrenIDs'] as $Children) {
                            if (IPS_VariableExists($Children)) {
                                $tmpObject = IPS_GetObject($Children);
                                $Instanz[$i]['ID'] = $tmpObject['ObjectID'];
                                $Instanz[$i]['Name'] = $tmpObject['ObjectName'];
                                $Instanz[$i]['ObjectIdent'] = $tmpObject['ObjectIdent'];
                                $Instanz[$i]['VariableTyp'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableType'];
                                $Instanz[$i]['VariableAction'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableAction'];
                                $Instanz[$i]['VariableCustomAction'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableAction'];
                                $Instanz[$i]['VariableProfile'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableProfile'];
                                $Instanz[$i]['VariableCustomProfile'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableCustomProfile'];
                                $Instanz[$i]['Value'] = GetValue($tmpObject['ObjectID']);
                                $i++;
                            }
                        }
                    } else {
                        $Topic = $this->TopicFromList($Object['ObjectID']);
                        $Instanz[0]['ID'] = $Object['ObjectID'];
                        $Instanz[0]['Name'] = $Object['ObjectName'];
                        $Instanz[0]['ObjectIdent'] = $Object['ObjectIdent'];
                        $Instanz[0]['VariableTyp'] = IPS_GetVariable($Object['ObjectID'])['VariableType'];
                        $Instanz[0]['VariableAction'] = IPS_GetVariable($Object['ObjectID'])['VariableAction'];
                        $Instanz[0]['VariableCustomAction'] = IPS_GetVariable($Object['ObjectID'])['VariableCustomAction'];
                        $Instanz[0]['VariableProfile'] = IPS_GetVariable($Object['ObjectID'])['VariableProfile'];
                        $Instanz[0]['VariableCustomProfile'] = IPS_GetVariable($Object['ObjectID'])['VariableCustomProfile'];
                        $Instanz[0]['Value'] = GetValue($Object['ObjectID']);
                    }

                    if ($Instanz != null) {
                        $Payload = json_encode($Instanz);
                        $this->SendMQTTData($Topic, $Payload);
                    }
                    if ($Topic == '') {
                        $this->SendDebug(__FUNCTION__, 'Topic for Object ID: ' . $Object['ObjectID'] . ' is not on list!', 0);
                    }
                }
            }
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('ReceiveData JSON', $JSONString, 0);
        $Data = json_decode($JSONString);

        //Für MQTT Fix in IPS Version 6.3
        if (IPS_GetKernelDate() > 1670886000) {
            $Data->Payload = utf8_decode($Data->Payload);
        }

        if (property_exists($Data, 'Topic')) {
            $arrTopic = explode('/', $Data->Topic);
            $CountItems = count($arrTopic);
            $Topic = $arrTopic[array_key_last($arrTopic)];
            $Payload = json_decode($Data->Payload);

            if ($Topic == 'set') {
                $this->SendDebug(__FUNCTION__ . 'Topic: ' . 'set ', $arrTopic[$CountItems - 2], 0);
                $this->SendDebug(__FUNCTION__ . 'Topic: ' . 'set Ident ', $Payload->ObjectIdent, 0);
                $this->SendDebug(__FUNCTION__ . 'Topic: ' . 'set Value ', $Payload->Value, 0);
                $ObjectID = $this->isTopicFromList($arrTopic[$CountItems - 2]);

                if ($ObjectID == $Payload->ObjectIdent) {
                    $VariablenID = $Payload->ObjectIdent;
                } else {
                    $VariablenID = IPS_GetObjectIDByIdent($Payload->ObjectIdent, $ObjectID);
                }
                if (HasAction($VariablenID)) {
                    RequestAction($VariablenID, $Payload->Value);
                } else {
                    SetValue($VariablenID, $Payload->Value);
                }
                return;
            }

            if ($Topic == 'get') {
                $this->SendDebug(__FUNCTION__, 'Topic: ' . 'get ' . $arrTopic[$CountItems - 2], 0);
                switch ($Payload->config) {
                    case 'variables':
                        $this->sendVariablen();
                        break;
                    default:
                        $this->SendDebug(__FUNCTION__, 'Invalid get Payload: ' . $Payload->config, 0);
                        break;
                }
                return;
            }

            $this->SendDebug(__FUNCTION__ . ' Topic', $Topic, 0);
            $ObjectID = $this->isTopicFromList($Topic);
            if ($ObjectID != 0) {
                $this->SendDebug(__FUNCTION__ . 'Topic exists on list', $Data->Topic, 0);
                $Object = IPS_GetObject($ObjectID);
                switch ($Object['ObjectType']) {
                    case 3:
                        if ($Data->Payload == '') {
                            IPS_RunScript($ObjectID);
                        }
                        break;
                    default:
                        $this->SendDebug(__FUNCTION__ . 'No Action for ObjectType', $Object['ObjectType'], 0);
                        break;
                }
            }
        }
    }

    public function sendData(string $Payload)
    {
        $Topic = $this->Devices($_IPS['SELF']);
        if ($Topic != '') {
            $this->SendMQTTData($Topic, $Payload);

            return true;
        }

        return false;
    }

    public function MQTTCommand(string $topic, string $payload)
    {
        $Data['DataID'] = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType'] = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain'] = $this->ReadPropertyBoolean('Retain');
        $Data['Topic'] = $topic;
        $Data['Payload'] = $payload;

        $DataJSON = json_encode($Data);
        $this->SendDebug(__FUNCTION__ . 'Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__, $DataJSON, 0);
        $this->SendDataToParent($DataJSON);
    }

    public function synchronizeData()
    {
        $this->sendConfiguration();
        $this->sendVariablenProfiles();
    }

    public function sendConfiguration()
    {
        $DevicesJSON = $this->ReadPropertyString('Devices');
        $Devices = json_decode($DevicesJSON);
        $Configuration = [];
        foreach ($Devices as $key => $Device) {
            $tmpConfiguration = [];
            $tmpConfiguration['ObjectID'] = $Device->ObjectID;
            $tmpConfiguration['ObjectName'] = IPS_GetObject($Device->ObjectID)['ObjectName'];
            $tmpConfiguration['MQTTTopic'] = $Device->MQTTTopic;
            $tmpConfiguration['ObjectType'] = IPS_GetObject($Device->ObjectID)['ObjectType'];
            array_push($Configuration, $tmpConfiguration);
        }
        $this->SendMQTTData('Configuration', json_encode($Configuration));
    }

    public function getVariablenProfileNames()
    {
        $DevicesJSON = $this->ReadPropertyString('Devices');
        $Devices = json_decode($DevicesJSON);
        $VariablenProfileNames = [];
        foreach ($Devices as $key => $Device) {
            $Object = IPS_GetObject($Device->ObjectID);
            switch ($Object['ObjectType']) {
               case 1:
                    $ChildrenIDs = $Object['ChildrenIDs'];
                    foreach ($ChildrenIDs as $ChildrenID) {
                        if (IPS_GetObject($ChildrenID)['ObjectType'] == 2) {
                            $VariablenProfileName = IPS_GetVariable($ChildrenID)['VariableProfile'];
                            if ($VariablenProfileName != '') {
                                if (!in_array($VariablenProfileName, $VariablenProfileNames)) {
                                    array_push($VariablenProfileNames, $VariablenProfileName);
                                }
                            }
                            $VariableCustomProfileName = IPS_GetVariable($ChildrenID)['VariableCustomProfile'];
                            if ($VariableCustomProfileName != '') {
                                if (!in_array($VariableCustomProfileName, $VariablenProfileNames)) {
                                    array_push($VariablenProfileNames, $VariableCustomProfileName);
                                }
                            }
                        }
                    }
                    break;
                case 2:
                    $VariablenProfileName = IPS_GetVariable($Device->ObjectID)['VariableProfile'];
                    if ($VariablenProfileName != '') {
                        if (!in_array($VariablenProfileName, $VariablenProfileNames)) {
                            array_push($VariablenProfileNames, $VariablenProfileName);
                        }
                    }
                        $VariableCustomProfileName = IPS_GetVariable($Device->ObjectID)['VariableCustomProfile'];
                        if ($VariableCustomProfileName != '') {
                            if (!in_array($VariableCustomProfileName, $VariablenProfileNames)) {
                                array_push($VariablenProfileNames, $VariableCustomProfileName);
                            }
                        }
                break;
                default:
                    break;
           }
        }
        return $VariablenProfileNames;
    }

    public function sendVariablenProfiles()
    {
        $ProfileNames = $this->getVariablenProfileNames();
        $VariablenProfiles = [];

        foreach ($ProfileNames as $ProfileName) {
            if ($ProfileName[0] != '~') {
                array_push($VariablenProfiles, IPS_GetVariableProfile($ProfileName));
            }
        }
        $this->SendMQTTData('VariablenProfiles', json_encode($VariablenProfiles));
    }

    public function sendVariablen()
    {
        $DevicesJSON = $this->ReadPropertyString('Devices');
        $Devices = json_decode($DevicesJSON);
        $Topic = '';
        $Instanz = null;

        foreach ($Devices as $key => $Device) {
            $Instanz = [];
            $Object = IPS_GetObject($Device->ObjectID);
            $this->SendDebug('ObjectID', $Device->ObjectID, 0);
            if ($Object['ObjectType'] == 1) {
                $Topic = $this->TopicFromList($Object['ObjectID']);
                $PObject = IPS_GetObject($Object['ObjectID']);
                $i = 0;
                foreach ($PObject['ChildrenIDs'] as $Children) {
                    if (IPS_VariableExists($Children)) {
                        $tmpObject = IPS_GetObject($Children);
                        $Instanz[$i]['ID'] = $tmpObject['ObjectID'];
                        $Instanz[$i]['Name'] = $tmpObject['ObjectName'];
                        $Instanz[$i]['ObjectIdent'] = $tmpObject['ObjectIdent'];
                        $Instanz[$i]['VariableTyp'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableType'];
                        $Instanz[$i]['VariableAction'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableAction'];
                        $Instanz[$i]['VariableCustomAction'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableAction'];
                        $Instanz[$i]['VariableProfile'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableProfile'];
                        $Instanz[$i]['VariableCustomProfile'] = IPS_GetVariable($tmpObject['ObjectID'])['VariableCustomProfile'];
                        $Instanz[$i]['Value'] = GetValue($tmpObject['ObjectID']);
                        $i++;
                    }
                }
            } else {
                $Topic = $this->TopicFromList($Object['ObjectID']);
                $Instanz[0]['ID'] = $Object['ObjectID'];
                $Instanz[0]['Name'] = $Object['ObjectName'];
                $Instanz[0]['ObjectIdent'] = $Object['ObjectIdent'];
                $Instanz[0]['VariableTyp'] = IPS_GetVariable($Object['ObjectID'])['VariableType'];
                $Instanz[0]['VariableAction'] = IPS_GetVariable($Object['ObjectID'])['VariableAction'];
                $Instanz[0]['VariableCustomAction'] = IPS_GetVariable($Object['ObjectID'])['VariableCustomAction'];
                $Instanz[0]['VariableProfile'] = IPS_GetVariable($Object['ObjectID'])['VariableProfile'];
                $Instanz[0]['VariableCustomProfile'] = IPS_GetVariable($Object['ObjectID'])['VariableCustomProfile'];
                $Instanz[0]['Value'] = GetValue($Object['ObjectID']);
            }
            if ($Instanz != null) {
                $Payload = json_encode($Instanz);
                //$Payload = $Instanz;
                $this->SendMQTTData($Topic, $Payload);
            }
            if ($Topic == '') {
                $this->SendDebug(__FUNCTION__, 'Topic for Object ID: ' . $Object['ObjectID'] . ' is not on list!', 0);
            }
        }
    }

    private function SendMQTTData(string $topic, string $payload)
    {
        $GroupTopic = $this->ReadPropertyString('GroupTopic');

        $Data['DataID'] = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType'] = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain'] = $this->ReadPropertyBoolean('Retain');
        $Data['Topic'] = 'mqttsync/' . $GroupTopic . '/' . $topic;
        $Data['Payload'] = $payload;

        $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__ . 'Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__, $DataJSON, 0);
        $this->SendDataToParent($DataJSON);
    }

    private function isInstance($ObjectID)
    {
        $Object = IPS_GetObject($ObjectID);

        if ($this->TopicFromList($Object['ParentID']) != '') {
            return true;
        }

        return false;
    }

    private function TopicFromList($ObjectID)
    {
        $DevicesJSON = $this->ReadPropertyString('Devices');
        $Devices = json_decode($DevicesJSON);
        foreach ($Devices as $Device) {
            if ($Device->ObjectID == $ObjectID) {
                return $Device->MQTTTopic;
            }
        }

        return '';
    }

    private function isTopicFromList($Topic)
    {
        $DevicesJSON = $this->ReadPropertyString('Devices');
        $Devices = json_decode($DevicesJSON);
        foreach ($Devices as $Device) {
            if ($Device->MQTTTopic == $Topic) {
                return $Device->ObjectID;
            }
        }
        $this->SendDebug(__FUNCTION__, 'Topic ' . $Topic . ' is not on list!', 0);

        return 0;
    }
}