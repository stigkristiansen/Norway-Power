<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";

class DayAhead extends IPSModule {
	use Profiles;

	const URL = 'https://norway-power.ffail.win';

	public function Create()
	{
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyString('Area', 'NO1');
		$this->RegisterPropertyBoolean('SkipSSLCheck', false);
		
		$this->RegisterAttributeString('Day', '');

		$this->RegisterProfileFloat('NPDA.Price', 'Dollar', '', ' kr/kWt', 4);

		$this->RegisterTimer('NorwayPowerRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

		$this->RegisterVariableFloat('Current', 'Aktuell', 'NPDA.Price', 1);
		$this->RegisterVariableFloat('Low', 'Lavest', 'NPDA.Price', 2);
		$this->RegisterVariableFloat('High', 'Høyest', 'NPDA.Price', 3);
		$this->RegisterVariableFloat('Avg', 'Gjennomsnitt', 'NPDA.Price', 4);
		$this->RegisterVariableFloat('Median', 'Median', 'NPDA.Price', 5);

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}

	public function Destroy()
	{
		$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
		if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
			$this->DeleteProfile('NPDA.Price');	
		}

		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges()
	{
		//Never delete this line!
		parent::ApplyChanges();

		if (IPS_GetKernelRunlevel() == KR_READY) {
			$this->InitTimer();
		}

		$this->HandleData();

	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

		if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitTimer();
		}
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);

			switch (strtolower($Ident)) {
				case 'refresh':
					$this->Refresh();						
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	private function Refresh() {
		$this->SetTimerInterval('NorwayPowerRefresh' . (string)$this->InstanceID, 3600*1000);

		$this->HandleData();
	}
	
	private function HandleData() {
		$fetchData = false;

		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$data = $this->ReadAttributeString('Day');
		if(strlen($data)>0) {
			$day = json_decode($data);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Data in attribute "Day" is "%s"', $data), 0);
			
			if(isset($day->date)) {
				if($day->date!=$today) {
					$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" has old data! Fetching new data', 0);
					$fetchData = true;						
				}
			} else {
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" has invalid data! Fetching new data', 0);
				$fetchData = true;
			}
		} else {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" is empty! Fetching new data', 0);
			$fetchData = true;
		}

		if($fetchData){
			$response = $this->GetDayAheadPrices($this->ReadPropertyString('Area'));
			if($response->success) {
				$receivedPrices = $response->result;

				$prices = array();
				foreach($receivedPrices as $price) {
					$prices[] = (float)$price->NOK_per_kWh;
				}

				if(count($prices)==24) {
					$data = array('date' => $today);
					$data['prices'] = $prices;

					$this->SendDebug(IPS_GetName($this->InstanceID), 'Saving prices...', 0);
					$this->SendDebug(IPS_GetName($this->InstanceID), json_encode($data), 0);
					$this->WriteAttributeString('Day', json_encode($data));
				} else {
					$this->SendDebug(IPS_GetName($this->InstanceID), 'Received invalid data from Internet', 0);
					return;
				}
			} else {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Unable to update atttribute "Day". Call failed: %s', $response->errortext), 0);
			}
		} else {
			$day = json_decode($data);
			
			if(!isset($day->prices)) {
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Atttribute "Day" has invalid data.', 0);
				$this->WriteAttributeString('Day', '');
				return;
			}
			
			$prices = $day->prices;
		}

		$stats = $this->GetStats($prices);

		$this->SetValue('Current', $stats->current);
		$this->SetValue('High', $stats->high);
		$this->SetValue('Low', $stats->low);
		$this->SetValue('Avg', $stats->avg);
		$this->SetValue('Median', $stats->median);

	}

	private function GetStats($Prices) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Calculating statistics...', 0);
		$date = new DateTime('Now');
		$currentIndex = $date->format('G');
		
		$stats = array('current' => (float)$Prices[$currentIndex]);
		
		sort($Prices, SORT_NUMERIC);
		
		$stats['high'] = (float)$Prices[count($Prices)-1];
		$stats['low'] = (float)$Prices[0];
		$stats['avg'] = (float)(array_sum($Prices)/count($Prices));

		$count = count($Prices);
		$index = floor($count/2);

		$stats['median'] = $count%2==0?(float)($Prices[$index-1]+$Prices[$index])/2:(float)$Prices[$index];

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Calculated statistics: %s', json_encode($stats)), 0);

		return (object)$stats;
		
	}

	private function InitTimer() {
		$this->SetTimerInterval('NorwayPowerRefresh' . (string)$this->InstanceID, (self::SecondsToNextHour()+1)*1000); 
	}

	private function SecondsToNextHour() {
		$date = new DateTime('Now');
		$secSinceLastHour = $date->getTimestamp() % 3600; 
		return (3600 - $secSinceLastHour);
	}

	private function GetDayAheadPrices(string $Area, $Date=null ) {
		$ch = curl_init();

		$skipSSLCheck = $this->ReadPropertyBoolean('SkipSSLCheck');
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$skipSSLCheck?0:2);//
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$skipSSLCheck);

		if($Date==null) {
			$date = new DateTime('Now');
		} else {
			$date = $Date;
		}

		$params = array('zone' => $this->ReadPropertyString('Area'));
		$params['date'] = $date->Format('Y-m-d');
	
		$url =  Self::URL . '?'  . http_build_query($params);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
					
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Fetching prices. Url is "%s"', $url), 0);

		$result = curl_exec($ch);

		$response = array('httpcode' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
		
		if($result===false) {
			$response['success'] = false;
			$response['errortext'] = curl_error($ch);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Failed to retrieve prices. The error was %s: %s', $response['httpcode'], $responce['errortext'] ), 0);

			return (object)$response;
		} 
		
		$response ['success'] = true;
		$response['result'] = json_decode($result) ;

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Got prices: %s', $result), 0);
		
		return  (object)$response;
	}
}