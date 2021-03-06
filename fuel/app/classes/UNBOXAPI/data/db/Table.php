<?php
namespace UNBOXAPI\Data\DB;

class Table {

    protected static $if_not_exists = false;
    protected static $engine = 'InnoDB';
    protected static $charset = 'utf8';
    protected static $db = 'default';

    private $model;
    private $relationships;
    private $relatedTables;

    public $name;
    public $fields;
    public $primaryKeys;
    public $foreignKeys = array();


    function __construct($model=null){
        if (isset($model)){
            $this->model = $model;
			$this->setConnectionFromModel();
            $this->name = $model::table();
            $this->setFieldsFromModel();
            $this->primaryKeys = $model::primary_key();
            $this->relationships = $model::relations();
            $this->setForeignKeysFromModel();
            $this->setRelationshipTablesFromModel();
        }
    }
    public static function defineFromDb($tableName){
        if(DBUtil::table_exists($tableName))
        {
            $Table = new Table();
            $Table->fields = \DB::list_columns($tableName,null,self::$db);

        }else{
            return false;
        }
    }
    public static function create($table,$attributes=""){
        $tableName = "";
        try{
            if (!(is_object($table))) {
                $tableName = $table;
                \Log::debug("Creating Table ".$tableName);
				if (!\DBUtil::table_exists($tableName,static::$db)) {
					\DBUtil::create_table($table,
										  $attributes['fields'],
										  $attributes['primary_keys'],
										  self::$if_not_exists,
										  self::$engine,
										  self::$charset,
										  $attributes['foreign_keys'],
										  static::$db
					);
				}
            }else{
                $tableName = $table->name;
                $foreignKeys = array();
                $count = 0;
                if (is_array($table->foreignKeys)) {
                    foreach ($table->foreignKeys as $key => $foreignKey) {
                        if (\DBUtil::table_exists($foreignKey['reference']['table'],static::$db)) {
                            $foreignKeys[$count] = $foreignKey;
                            $table->foreignKeys[$key]['added'] = true;
                            $count++;
                        } else {
                            $table->foreignKeys[$key]['added'] = false;
                        }
                    }
                }
                \Log::debug("Creating Table ".$tableName);
				if (!\DBUtil::table_exists($tableName,static::$db)) {
					\DBUtil::create_table($table->name,
										  $table->fields,
										  $table->primaryKeys,
										  self::$if_not_exists,
										  self::$engine,
										  self::$charset,
										  $foreignKeys,
										  static::$db
					);
				}
            }
            return \DBUtil::table_exists($tableName,static::$db);
        }catch(\Database_Exception $ex){
            \Log::error("Failed to create table. Exception: (".$ex->getCode().") ".$ex->getMessage());
            return false;
        }
    }
    public static function addField($table,array $fields){
        foreach($fields as $field => $definition){
            \DBUtil::add_field($table,$definition,self::$db);
        }
    }
    public static function addForeignKey($table,array $foreignKey){
        try{
            if (\DBUtil::field_exists($table,$foreignKey['key'],self::$db)) {
                \Log::info("Adding foreign key [" . $foreignKey['key'] . "] to " . $table . ".");
                \DBUtil::add_foreign_key($table, $foreignKey);
            }else{
                \Log::info("Key [" . $foreignKey['key'] . "] not added. Field does not exist on " . $table . ".");
            }
            return true;
        }catch(\Database_Exception $ex){
            \Log::error("Foreign key [".$foreignKey['key']."] not added to $table. Exception: (".$ex->getCode().") ".$ex->getMessage());
            return false;
        }
    }
    public function setForeignKeys(array $foreignKeys){
        foreach($foreignKeys as $foreignKey => $attributes){
            $this->foreignKeys[] = array(
                'key' => $attributes['key'],
                'reference' => array(
                    'table' => $attributes['reference']['table'],
                    'column' => $attributes['reference']['column'],
                ),
                'on_update' => (isset($attributes['on_update']) ? $attributes['on_update'] : 'NO ACTION'),
                'on_delete' => (isset($attributes['on_delete']) ? $attributes['on_delete'] : 'NO ACTION')
            );
        }
    }
    public function setFields(array $fields){
        foreach ($fields as $field => $attributes){
            $this->fields[$field] = array(
                'type' => (isset($attributes['data_type'])?$attributes['data_type']:$attributes['type'])
            );
            if (isset($attributes['auto_inc'])){
                $this->fields[$field]['auto_increment'] = $attributes['auto_inc'];
            }
            if (isset($attributes['null'])){
                $this->fields[$field]['null'] = $attributes['null'];
            }else{
                $this->fields[$field]['null'] = true;
            }
            if (isset($attributes['unsigned'])) {
                $this->fields[$field]['unsigned'] = $attributes['unsigned'];
            }
            if (isset($attributes['default'])){
                $this->fields[$field]['default'] = $attributes['default'];
            }
            if (isset($attributes['validation'])){
                if (isset($attributes['validation']['max_length'])){
                    $this->fields[$field]['constraint'] = $attributes['validation']['max_length'];
                }
            }else if (isset($attributes['constraint'])){
                $this->fields[$field]['constraint'] = $attributes['constraint'];
            }
        }
        return $this->fields;
    }
	public function setConnection($connection){
		static::$db = $connection;
	}
	private function setConnectionFromModel(){
		$model = $this->model;
		$this->setConnection($model::connection());
	}
    //Model specific functions
    private function setFieldsFromModel(){
        $model = $this->model;
        $properties = $model::properties();
        $this->setFields($properties);
    }
    private function setForeignKeysFromModel(){
        $foreignKeys = array();
        foreach($this->relationships as $relationshipName => $relationshipObject) {
            if (strpos(get_class($relationshipObject),"HasOne")>0||strpos(get_class($relationshipObject),"BelongsTo")>0) {
                $relatedModel = $relationshipObject->__get("model_to");
                $keyFrom = $relationshipObject->__get("key_from");
                $keyTo = $relationshipObject->__get("key_to");
                if (!(in_array($keyFrom, $this->primaryKeys)||$keyFrom==$this->primaryKeys)){
                    $foreignKeys[] = array(
                        'key' => $keyFrom[0],
                        'reference' => array(
                            'table' => $relatedModel::table(),
                            'column' => $keyTo[0],
                        ),
                        'on_update' => ($relationshipObject->__get("cascade_save") ? 'CASCADE' : 'NO ACTION'),
                        'on_delete' => ($relationshipObject->__get("cascade_delete") ? 'CASCADE' : 'NO ACTION')
                    );
                }
            }
        }
        $this->setForeignKeys($foreignKeys);
    }
    private function setRelationshipTablesFromModel(){
        foreach($this->relationships as $relationshipName => $relationshipObject){
            if (strpos(get_class($relationshipObject),"ManyMany")>0){
                $tableName = $relationshipObject->__get("table_through");
                $key = $relationshipObject->__get("key_through_from");
                $column = $relationshipObject->__get("key_from");
                $this->relatedTables[$tableName] = array(
                    'foreign_keys' => array(
                        array(
                            'key' => $key[0],
                            'reference' => array(
                                'table' => $this->name,
                                'column' => $column[0],
                            ),
                            'on_update' => ($relationshipObject->__get("cascade_save") ? 'CASCADE' : 'NO ACTION'),
                            'on_delete' => ($relationshipObject->__get("cascade_delete") ? 'CASCADE' : 'NO ACTION')
                        )
                    ),
                );
				if (!isset($this->relatedTables[$tableName]['connection'])){
					$this->relatedTables[$tableName]['connection'] = array(static::$db);
				}
                $this->relatedTables[$tableName]['fields'][$key[0]] = array(
                    'type' => 'varchar',
                    'constraint' => 50,
                    'null' => false,
                );
            }
        }
    }
    public function getRelatedTables(){
        return $this->relatedTables;
    }
}