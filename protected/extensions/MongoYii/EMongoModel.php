<?php

/**
 * WHY IS THERE NO FUCKING GOOD NAME FOR THIS FUCKING PIECE OF SHIT
 */
class EMongoModel extends CModel{

	/**
	 * @var EMongoClient the default database connection for all active record classes.
	 * By default, this is the 'mongodb' application component.
	 * @see getDbConnection
	 */
	public static $db;

	private $_attributes = array();
	private $_related = array();

	/**
	 * (non-PHPdoc)
	 * @see yii/framework/CComponent::__get()
	 */
	public function __get($name){
		
		if(isset($this->_related[$name])){
			return $this->_related[$name];
		}elseif(array_key_exists($name, $this->relations())){
			return $this->_related[$name]=$this->getRelated($name);
		}elseif(isset($this->attributes[$name])){
			return $this->_attributes[$name];
		}else{
			return parent::__get($name);
		}
		
	}

	/**
	 * (non-PHPdoc)
	 * @see CComponent::__set()
	 */
	public function __set($name,$value){
		
		if($this->setAttribute($name,$value)===false)
		{
			if(isset($this->_related[$name]) || array_key_exists($name, $this->relations()))
				$this->_related[$name]=$value;
			else
				parent::__set($name,$value);
		}		
		
	}
	
	/**
	 * (non-PHPdoc)
	 * @see CComponent::__isset()
	 */
	public function __isset($name){
		
		if(isset($this->_attributes[$name]))
			return true;
		elseif(isset($this->_related[$name]))
			return true;
		elseif(array_key_exists($name, $this->relations()))
			return $this->getRelated($name)!==null;
		else
			return parent::__isset($name);		
		
	}	
	
	/**
	 * (non-PHPdoc)
	 * @see CComponent::__unset()
	 */
	public function __unset($name){
		
		if(isset($this->_attributes[$name]))
			unset($this->_attributes[$name]);
		elseif(isset($this->_related[$name]))
			unset($this->_related[$name]);
		else
			parent::__unset($name);
		
	}	
	
	/**
	 * (non-PHPdoc)
	 * @see CComponent::__call()
	 */
	public function __call($name,$parameters)
	{
		if(array_key_exists($name, $this->relations()))
		{
			if(empty($parameters))
				return $this->getRelated($name,false);
			else
				return $this->getRelated($name,false,$parameters[0]);
		}
	
		return parent::__call($name,$parameters);
	}	

	function __construct($scenario = 'insert'){

		if($scenario===null) // internally used by populateRecord() and model()
			return;

		$this->setScenario($scenario);

		// Run reflection and cache it if not already there
		if(!$this->getDbConnection()->getObjCache(get_class($this)) && get_class($this) != 'EMongoModel' /* We can't cache the model */){
			$virtualFields = array();
			$documentFields = array();

			$reflect = new \ReflectionClass(get_class($this));
			$class_vars = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED); // Pre-defined doc attributes

			foreach ($class_vars as $prop) {
				
				if($prop->isStatic())
					continue;
					
				$docBlock = $prop->getDocComment();

				// If it is not public and it is not marked as virtual then assume it is document field
				if($prop->isProtected() || preg_match('/@virtual/i', $docBlock) <= 0){
					$documentFields[] = $prop->getName();
				}else{
					$virtualFields[] = $prop->getName();
				}
			}
			$this->getDbConnection()->setObjectCache(get_class($this),
				sizeof($virtualFields) > 0 ? $virtualFields : null,
				sizeof($documentFields) > 0 ? $documentFields : null
			);
		}

		$this->init();

		$this->attachBehaviors($this->behaviors());
		$this->afterConstruct();
	}

	/**
	 * Initializes this model.
	 * This method is invoked when an AR instance is newly created and has
	 * its {@link scenario} set.
	 * You may override this method to provide code that is needed to initialize the model (e.g. setting
	 * initial property values.)
	 */
	public function init(){ return true; }

	/**
	 * (non-PHPdoc)
	 * @see CModel::attributeNames()
	 */
	function attributeNames(){
		
		$fields = $this->getDbConnection()->getFieldObjCache(get_class($this));
		$virtuals = $this->getDbConnection()->getVirtualObjCache(get_class($this));
		
		$cols = array_merge(is_array($fields) ? $fields : array(), is_array($virtuals) ? $virtuals : array());
		return array_keys($cols!==null ? $cols : array());
	}

	/**
	 * 
	 * @return multitype:
	 */
	function relations(){ return array(); }
	
	/**
	 * Sets the attribute of the model
	 * @param string $name
	 * @param mixed $value
	 */
	public function setAttribute($name,$value){
		
		if(property_exists($this,$name))
			$this->$name=$value;
		elseif(isset($this->_attributes[$name]))
			$this->_attributes[$name]=$value;
		else
			return false;
		return true;
		
	}	
	
	/**
	 * (non-PHPdoc)
	 * @see CModel::getAttributes()
	 */
	public function getAttributes($names=true)
	{
		$attributes=$this->_attributes;
		$fields = $this->getDbConnection()->getFieldObjCache(get_class($this));
		
		if(is_array($fields)){	
			foreach($fields as $name=>$column)
			{
				if(property_exists($this,$name))
					$attributes[$name]=$this->$name;
				elseif($names===true && !isset($attributes[$name]))
					$attributes[$name]=null;
			}
		}
		if(is_array($names))
		{
			$attrs=array();
			foreach($names as $name)
			{
				if(property_exists($this,$name))
					$attrs[$name]=$this->$name;
				else
					$attrs[$name]=isset($attributes[$name])?$attributes[$name]:null;
			}
			return $attrs;
		}
		else
			return $attributes;
	}

	/**
	 * (non-PHPdoc)
	 * @see CModel::validate()
	 */
	public function validate($attributes=null, $clearErrors=true)
	{
		// We copy this function to add the subdocument validator as a built in validator
		CValidator::$builtInValidators['subdocument'] = 'ESubdocumentValidator';
		
		if($clearErrors)
			$this->clearErrors();
		if($this->beforeValidate())
		{
			foreach($this->getValidators() as $validator)
				$validator->validate($this,$attributes);
			$this->afterValidate();
			return !$this->hasErrors();
		}
		else
			return false;
	}	

	/**
	 * Returns the database connection used by active record.
	 * By default, the "mongodb" application component is used as the database connection.
	 * You may override this method if you want to use a different database connection.
	 * @return EMongoClient the database connection used by active record.
	 */
	public function getDbConnection()
	{
		if(self::$db!==null)
			return self::$db;
		else
		{
			self::$db=Yii::app()->mongodb;
			if(self::$db instanceof EMongoClient)
				return self::$db;
			else
				throw new EMongoException(Yii::t('yii','MongoDB Active Record requires a "mongodb" EMongoClient application component.'));
		}
	}
	
	function getDocument(){
		
		$attributes = $this->getDbConnection()->getFieldObjCache(get_class($this));
		$doc = array();
		
		foreach($attributes as $field)
			$doc[$field] = $this->$field;
		return array_merge($doc, $this->_attributes);
	}
	
	function getRawDocument(){
		return $this->filterRawDocument($this->getDocument());
	}
	
	function filterRawDocument($doc){
		if(is_array($doc)){
			foreach($doc as $k => $v){
				if(is_array($v)){
					$doc[$k] = $this->{__FUNCTION__}($doc[$k]);
				}elseif($v instanceof EMongoModel || $v instanceof EMongoDocument){
					$doc[$k] = $doc[$k]->getRawDocument();
				}
			}
		}
		return $doc;
	}	
	
	function getJSONDocument(){
		return json_encode($this->getRawDocument());
	}
	
	function getBSONDocument(){
		return bson_encode($this->getRawDocument());	
	}
}