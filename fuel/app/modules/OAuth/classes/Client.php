<?php

namespace OAuth;

use \UNBOXAPI\Data\Metadata\Manager as MetadataManager;

class Client {

    protected static $_name = 'OAuth';
    protected static $_config;
	protected static $_token;
	protected static $_user_info;

    protected $_server;
    protected $_id;
    protected $_secret;
	protected $_type;
    protected $_grant_type;
	protected $_scope = array(
		'api'
	);

    public $payload;

    private $config;
    private $request;

    public static function config(){
        static::$_config = \Config::load(static::$_name."::module");
        return static::$_config;
    }
    public static function makeCookie($tokenInfo){
        $serializedToken = serialize($tokenInfo);
        $data = \Crypt::encode($serializedToken);
        \Cookie::set('unauth',$data);
    }
    public static function getCookie($crypted=false){
        $unauth = \Cookie::get('unauth');
        if ($unauth!==null) {
			if ($crypted===false) {
				$serializedToken = \Crypt::decode($unauth);
				$token          = unserialize($serializedToken);
				return $token;
			}else {
				return $unauth;
			}
        }
        return false;
    }
	public static function deleteCookie(){
		\Cookie::set('unauth',"1",1);
		\Cookie::delete("unauth");
	}
	public static function getInstance($interface = false)
	{
		static $instance = null;
		if (null === $instance) {
			$instance = new static($interface);
		}
		return $instance;
	}
	/**
	 * @param $type = Type of client accessing system. API or JS
	 */
	protected function __construct(){
        $this->setServer();
		$this->setId(\Config::get('unbox.oauth.client.id'));
		$this->setSecret(\Config::get('unbox.oauth.client.secret'));
        $this->config = static::config();
    }
	private function __clone()
	{
	}
	private function __wakeup()
	{
	}

	public static function token(){
		return static::$_token;
	}
	public static function user($val=null){
		\Log::debug(print_r(static::$_user_info,true));
		if (($val=='id'||$val=='scopes')&&!empty(static::$_user_info)){
			return static::$_user_info[$val];
		}
		return static::$_user_info;
	}
    public function setGrantType($grant){
        if (in_array($grant,$this->config['grant_types'])){
            $this->_grant_type = $grant;
        }else{
            return false;
        }
    }
    public function validateAuth($token,$requiredScope,$auto_refresh=true){
		$valid = FALSE;
		if (!($this->validateToken($token))){
			if ($auto_refresh){
				if (isset($this->_id) && isset($this->_secret)) {
					if ($this->refreshToken($token['refresh_token']) !== NULL) {
						static::makeCookie(static::$_token);
						$valid = TRUE;
					}
				}
			}
		}else{
			$valid = TRUE;
		}
		if ($valid === TRUE){
			$this->getCachedUser(static::$_token['refresh_token']);
			if (!$this->validateScope($requiredScope)){
				$valid = FALSE;
			}
		}
		//Hack::MetadataManager needs to know if current process is authorized or not, so I added it here for simplicity sake
		MetadataManager::loggedIn($valid);
        return $valid;
    }
    private function validateToken($token){
        $valid = FALSE;
		$cachedToken = $this->checkCache($token);
		if ($cachedToken !== FALSE){
			if ($token['refresh_token']==$cachedToken['refresh_token']){
				\Log::debug("Token is valid");
				static::$_token = $token;
				$valid = TRUE;
			}
		}
        return $valid;
    }
	private function validateScope($requiredScope){
		$valid = FALSE;
		$scopes = static::user('scopes');
		if (is_array($scopes)) {
			\Log::debug("Required Scope: $requiredScope");
			\Log::debug("Current scopes: ".serialize($scopes));
			if (in_array($requiredScope, $scopes)) {
				\Log::debug("Scope is valid");
				$valid = TRUE;
			}
		}
		return $valid;
	}
	public function refreshToken($refresh_token=null){
		$this->setGrantType("refresh_token");
		if ($refresh_token==null){
			$refresh_token = static::$_token['refresh_token'];
		}
		if (empty(static::$_user_info)){
			$this->getCachedUser($refresh_token);
		}
		$this->payload = array(
			'refresh_token' => $refresh_token,
			'scopes' => implode(',',static::user('scopes'))
		);

		$token = $this->issueAccessToken();
		return $token;
	}
    public function issueAccessToken(){
		if (isset($this->_id) && isset($this->_secret)){
			$this->setupPayload();
			$this->setupRequest();
			$Response = $this->request->execute($this->payload)->response();
			$response = json_decode($Response->body(), TRUE);
			if ($Response->status == '200') {
				static::$_token = $response;
				$this->cacheToken(static::$_token);
				$this->setupRequest("user");
				$userResponse = $this->request->execute(array('token' => static::$_token['access_token']))->response();
				if ($userResponse->status == '200') {
					$userInfo         = json_decode($userResponse->body(), TRUE);
					static::$_user_info = $userInfo;
					$this->cacheUser($userInfo);
				}
				return static::$_token;
			}else{
				if (strpos($response,"invalid")!== FALSE && $this->_grant_type == 'refresh_token'){
					$this->deleteUserCache($this->payload['refresh_token']);
					static::deleteCookie();
				}
				throw new \Exception($response);
			}
		}else{
			throw new \Exception("No client id and secret specified.");
		}
    }
	public function revokeToken() {
		$this->deleteCache(static::$_token);
		$this->payload = array(
			'token' => static::$_token['refresh_token']
		);
		$this->setupRequest('revoke');
		return $this->request->execute($this->payload)->response();
	}
	public function setId($id){
		$this->_id = $id;
		return $this->_id;
	}
	public function setSecret($secret){
		$this->_secret = $secret;
		return $this->_secret;
	}
	public function setScope($scope,$append = true){
		if ($append){
			$this->_scope = array_merge($this->_scope,$scope);
		}else{
			$this->_scope = $scope;
		}
		return $this->_scope;
	}
	private function setServer(){
		$this->_server = \Config::get('unbox.oauth.server.host');
		return $this->_server;
	}
	private function checkCache($token){
		try
		{
			\Log::debug("Looking for: {$token['access_token']}");
			$cachedToken = \Cache::get("tokens.{$token['access_token']}");
			$cachedToken = \Crypt::decode($cachedToken);
			$cachedToken = unserialize($cachedToken);
			return $cachedToken;
		}
		catch (\CacheNotFoundException $e) {
			\Log::debug("OAuth token not found in cache. Token is presumed expired.");
			return false;
		}
	}
	private function getCachedUser($refresh_token=null){
		try
		{
			if ($refresh_token==null){
				$refresh_token = static::$_token['refresh_token'];
			}
			\Log::debug("Looking for: $refresh_token");
			$cachedUser = \Cache::get("users.$refresh_token");
			$cachedUser = \Crypt::decode($cachedUser);
			\Log::debug($cachedUser);
			static::$_user_info = unserialize($cachedUser);
		}
		catch (\CacheNotFoundException $e) {
			\Log::debug("User not found in cache.");
			static::$_user_info = null;
		}
	}
	private function cacheToken($token){
		$cryptToken = \Crypt::encode(serialize($token));
		\Cache::set("tokens.{$token['access_token']}",$cryptToken,3590);
	}
	private function deleteCache($token){
		$this->deleteTokenCache($token['access_token']);
		$this->deleteUserCache($token['refresh_token']);
	}
	private function deleteTokenCache($access_token){
		\Cache::delete("tokens.$access_token");
	}
	private function deleteUserCache($refresh_token){
		\Cache::delete("users.$refresh_token");
	}
	private function cacheUser($userInfo){
		$cryptUser = \Crypt::encode(serialize($userInfo));
		\Cache::set("users.".static::$_token['refresh_token'],$cryptUser,86400);
	}
    private function setupPayload(){
        $clientAttributes = array(
            'client_id' => $this->_id,
            'client_secret' => $this->_secret,
            'grant_type' => $this->_grant_type,
			'scope' => implode(",",$this->_scope)
        );
		if ($this->_grant_type=='password'){
			$clientAttributes['username'] = \Input::param('username');
			$clientAttributes['password'] = \Input::param('password');
		}
        if (is_array($this->payload)){
            $this->payload = array_merge($clientAttributes,$this->payload);
        }else{
            $this->payload = $clientAttributes;
        }
    }
    private function buildURL(){
        $url = "";
        switch ($this->_grant_type){
			case "client":
            case "password":
                $url = "token";
                break;
            case "refresh_token":
                $url = "refresh";
                break;
        }
        return "oauth/v1/oauth".DIRECTORY_SEPARATOR.$url;
    }
    private function setupRequest($url=null,$httpMethod="POST"){
        if ($url==null) {
            $url = $this->buildURL();
        }else{
            $url = "oauth/v1/oauth".DIRECTORY_SEPARATOR.$url;
        }
		if ($this->_server=='localhost'){
			$this->request = \Request::forge($url,true);
		}else{
			$url = $this->_server.$url;
			$this->request = \Request::forge($url,'curl');
			$this->request->set_method($httpMethod);
		}
        return $this->request;
    }
}