<?php
namespace NovakSolutions\Infusionsoft;

/**
 * Class App
 * @package NovakSolutions\Infusionsoft
 * @property Providers\TokenStorageProvider $tokenStorageProvider
 * @property xmlrpc_client $client
 */
class App{
    protected $usingOAuth = false;
    protected $tokenStorageProvider = null;
	protected $hostname = '';
	protected $accessToken = '';
    protected $apiKey = '';

	protected $port;
    protected $timeout = 0;
	protected $debug = false;

	protected $exceptions = array();
	protected $client;

    protected $totalHttpCalls = 0;
    protected $Logger;

	public function __construct($hostname = '', $apiKeyOrStorageProvider = null, $port = 443){
        if(strpos($hostname, ".") === false){
            $hostname = $hostname . '.infusionsoft.com';
        }

		$this->hostname = $hostname;

        if(is_a($apiKeyOrStorageProvider, 'NovakSolutions\Infusionsoft\TokenStorageProvider')){
            /** @var TokenStorageProvider $storageProvider */
            $this->tokenStorageProvider = $apiKeyOrStorageProvider;
        } elseif($apiKeyOrStorageProvider == null) {
            $this->tokenStorageProvider = AppPool::getDefaultStorageProvider();
        }

        if($this->tokenStorageProvider != null){
            $this->usingOAuth = true;
            $tokens = $this->tokenStorageProvider->getTokens($this->hostname);
            $this->accessToken = $tokens['accessToken'];
            $this->refreshToken = $tokens['refreshToken'];
            $this->tokenExpiresAt = $tokens['expiresAt'];
        }

		$this->port = $port;

        if($this->usingOAuth){
            $this->client	= new xmlrpc_client('/crm/xmlrpc/v1', 'api.infusionsoft.com', 443);
        } else {
            $this->client	= new xmlrpc_client('/api/xmlrpc', $this->getHostname(), $this->port);
        }

		$this->client->setSSLVerifyPeer(true);
        $this->client->setCaCertificate(dirname(__FILE__) . '/mozilla-ca-root-cert-bundle.pem');
        $this->client->request_charset_encoding = "UTF-8";

        if($this->usingOAuth == true){
            $this->client->extraUrlParams = array('access_token' => $this->accessToken);
        }
	}

    /**
     * @param $hostName
     * @param string $apiKey
     * @return App
     */
    public static function connect($tokenStorageProvider = null){
        if($tokenStorageProvider == null){
            $tokenStorageProvider =  AppPool::getDefaultStorageProvider();
        }

        $hostName = $tokenStorageProvider->getFirstAppName();

        return AppPool::addApp(new App($hostName));
    }

    public function logger(Logger $object){
        if (method_exists($object, 'log')){
            $this->Logger = $object;
        } else {
            throw new Exception('Required method "log" not found in object passed to App::statisticsLogger)');
        }
    }

    public function enableDebug(){
        $this->debug = true;
        $this->client->dump_payloads = true;
    }

	public function getAccessToken(){
		return $this->accessToken;
	}

	public function getHostname(){
		return $this->hostname;
	}

	public function getPort(){
		return $this->port;
	}		

	public function getExceptions(){
		return $this->exceptions;
	}
	public function addException(Exception $e){
		$this->exceptions[] = $e;
	}

	public function sendWithoutAddingKey($method, $args, $retry = false){
        $encoded_arguments = array();
        foreach($args as $argument){
            $encoded_arguments[] = php_xmlrpc_encode($argument, array('auto_dates'));
        }

		$call = new xmlrpcmsg($method, $encoded_arguments);

        $attempts = 0;
        $start = time();
        $req = null;
        do{

            if ($attempts > 0){
                if (class_exists('CakeLog') && $attempts > 1){
                    $lastAttemptFaultCode = $req->faultCode();
                    $lastAttemptFaultString = $req->faultString();
                }
                sleep(5);
            }
            $attempts++;
            $req = $this->client->send($call, $this->timeout, 'https');
            if($req != null && strpos($req->faultString(), 'Didn\'t receive 200 OK') !== false){
                self::refreshTokens();
                $req = $this->client->send($call, $this->timeout, 'https');
            }
        } while(
            $retry &&
                (
                    $req->faultCode() == $GLOBALS['xmlrpcerr']['invalid_return'] ||
                    $req->faultCode() == $GLOBALS['xmlrpcerr']['curl_fail'] ||
                    strpos($req->faultString(), 'com.infusionsoft.throttle.ThrottlingException: Maximum number of threads throttled') !== false ||
                    strpos($req->faultString(), 'Didn\'t receive 200 OK') !== false
                )
            && $attempts < 3
        );

        $this->totalHttpCalls += $attempts;
        if (!$req->faultCode()){
            if(is_object($req->value())){
                $result = php_xmlrpc_decode($req->value());
            } else {
                $result = $req->value();
            }

        } else {
            $result = array();
        }

        if (is_object($this->Logger)){
            $this->Logger->log(array(
                'time' => date('Y-m-d H:i:s'),
                'duration' => time() - $start,
                'method' => $method,
                'args' => $args,
                'attempts' => $attempts,
                'result' => $req->faultCode() ? 'Failed' : count($result) . ' Records Returned',
                'error_message' => $req->faultCode() ? $req->faultString() : null,
            ));
        }

		if ($req->faultCode()){
			$exception = new Exception($req->faultString() . "\nAttempted: $attempts time(s).", $method, $args);
			$this->addException($exception);			
			throw $exception; 
			return FALSE;
		}
        if ($attempts > 2){
            CakeLog::write('notice', "Infusionsoft call required $attempts calls to receive a successful response. Method: $method FaultCode: $lastAttemptFaultCode FaultString: $lastAttemptFaultString");
        }
		return $result;
	}
	public function send($method, $args, $retry = false){
		array_unshift($args, $this->usingOAuth ? $this->accessToken : $this->apiKey);
		return $this->sendWithoutAddingKey($method, $args, $retry);
	}

    public function getTotalHttpCalls(){
        return $this->totalHttpCalls;
    }


    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }

    public static function formatDate($dateStr) {
        $dArray=date_parse($dateStr);
        if ($dArray['error_count']<1) {
            $tStamp =
                mktime($dArray['hour'],$dArray['minute'],$dArray['second'],$dArray['month'],
                    $dArray['day'],$dArray['year']);
            return date('Ymd\TH:i:s',$tStamp);
        } else {
            $message = '';
            foreach ($dArray['errors'] as $err) {
                $message .= "ERROR: " . $err . "\n";
            }
            throw new Exception($message);
        }
    }

    public static function ping(App $app = null){
        if($app == null){
            $app = AppPool::getApp();
        }

        $pingResult = false;
        try{
            $result = $app->sendWithoutAddingKey( 'DataService.echo', array('Hello World'), false);
            if($result) $pingResult = true;
        }
        catch(Exception $e){
            $out = FALSE;
        }
        return $out;
    }

    public function hasTokens(){
        return $this->accessToken != '' && $this->refreshToken != '';
    }

    public function refreshTokens(){
        $tokens = OAuth2::refreshToken($this->refreshToken);
        $this->updateAndSaveTokens($tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']);
    }

    public function updateAndSaveTokens($accessToken, $refreshToken, $expiresIn){
        $this->tokenStorageProvider->saveTokens($this->hostname, $accessToken, $refreshToken, $expiresIn);
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->tokenExpires = time() + $expiresIn;
        $this->client->extraUrlParams = array('access_token' => $this->accessToken);
    }
}