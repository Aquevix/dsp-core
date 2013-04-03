<?php
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Log;

/**
 * BaseDspModel.php
 *
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 * Copyright (c) 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Defines two "built-in" behaviors: DataFormat and TimeStamp
 *  - DataFormat automatically formats date/time values for the target database platform (MySQL, Oracle, etc.)
 *  - TimeStamp automatically updates create_date and lmod_date columns in tables upon save.
 *
 * @property int    $id
 * @property string $created_date
 * @property string $last_modified_date
 */
class BaseDspModel extends \CActiveRecord
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const ALL_ATTRIBUTES = '*';

	//*******************************************************************************
	//* Members
	//*******************************************************************************

	/**
	 * @var array Our schema, cached for speed
	 */
	protected $_schema;
	/**
	 * @var array Attribute labels cache
	 */
	protected $_attributeLabels = array();
	/**
	 * @var string The name of the model class
	 */
	protected $_modelClass = null;
	/**
	 * @var \CDbTransaction The current transaction
	 */
	protected $_transaction = null;

	//********************************************************************************
	//* Methods
	//********************************************************************************

	/**
	 * Init
	 */
	public function init()
	{
		$this->_modelClass = get_class( $this );
		parent::init();
	}

	/**
	 * Returns this model's schema
	 *
	 * @return array()
	 */
	public function getSchema()
	{
		return $this->_schema ? $this->_schema : $this->_schema = $this->getMetaData()->columns;
	}

	/**
	 * @return array
	 */
	public function attributeLabels()
	{
		static $_cache;

		if ( null !== $_cache )
		{
			return $_cache;
		}

		return $_cache = array_merge(
			parent::attributeLabels(),
			array(
				 'id'                  => 'ID',
				 'create_date'         => 'Created Date',
				 'created_date'        => 'Created Date',
				 'last_modified_date'  => 'Last Modified Date',
				 'lmod_date'           => 'Last Modified Date',
				 'created_by_id'       => 'Created By',
				 'last_modified_by_id' => 'Last Modified By',
			)
		);
	}

	/**
	 * @param string $attribute
	 *
	 * @return array
	 */
	public function attributeLabel( $attribute )
	{
		return Option::get( $this->attributeLabels(), $attribute );
	}

	/**
	 * PHP sleep magic method.
	 * Take opportunity to flush schema cache...
	 *
	 * @return array
	 */
	public function __sleep()
	{
		//	Clean up and phone home...
		$this->_schema = null;

		return parent::__sleep();
	}

	/**
	 * Override of CModel::setAttributes
	 * Populates member variables as well.
	 *
	 * @param array $attributes
	 * @param bool  $safeOnly
	 *
	 * @return void
	 */
	public function setAttributes( $attributes, $safeOnly = true )
	{
		if ( !is_array( $attributes ) )
		{
			return;
		}

		$_attributes = array_flip( $safeOnly ? $this->getSafeAttributeNames() : $this->attributeNames() );

		foreach ( $attributes as $_column => $_value )
		{
			if ( isset( $_attributes[$_column] ) )
			{
				$this->setAttribute( $_column, $_value );
			}
			else
			{
				if ( $this->canSetProperty( $_column ) )
				{
					$this->{$_column} = $_value;
				}
			}
		}
	}

	/**
	 * Sets our default behaviors
	 *
	 * @return array
	 */
	public function behaviors()
	{
		return array_merge(
			parent::behaviors(),
			array(
				 //	Data formatter
				 'base_model.data_format_behavior' => array(
					 'class' => 'application.behaviors.DataFormatBehavior',
				 ),
				 //	Timestamper
				 'base_model.timestamp_behavior'   => array(
					 'class'              => 'application.behaviors.TimestampBehavior',
					 'createdColumn'      => 'created_date',
//					 'createdByColumn'      => 'created_by_id',
					 'lastModifiedColumn' => 'last_modified_date',
//					 'lastModifiedByColumn' => 'last_modified_by_id',
				 ),
			)
		);
	}

	/**
	 * Returns the errors on this model in a single string suitable for logging.
	 *
	 * @param string $attribute Attribute name. Use null to retrieve errors for all attributes.
	 *
	 * @return string
	 */
	public function getErrorsForLogging( $attribute = null )
	{
		$_result = null;
		$_i = 1;

		$_errors = $this->getErrors( $attribute );

		if ( !empty( $_errors ) )
		{
			foreach ( $_errors as $_attribute => $_error )
			{
				$_result .= $_i++ . '. [' . $_attribute . '] : ' . implode( '|', $_error );
			}
		}

		return $_result;
	}

	/**
	 * Forces an exception on failed save
	 *
	 * @param bool  $runValidation
	 * @param array $attributes
	 *
	 * @throws CDbException
	 * @return bool
	 */
	public function save( $runValidation = true, $attributes = null )
	{
		if ( !parent::save( $runValidation, $attributes ) )
		{
			throw new \CDbException( $this->getErrorsForLogging() );
		}

		return true;
	}

	/**
	 * A mo-betta CActiveRecord update method. Pass in column => value to update.
	 * NB: validation is not performed in this method. You may call {@link validate} to perform the validation.
	 *
	 * @param array $attributes list of attributes and values that need to be saved. Defaults to null, meaning all attributes that are loaded from DB will be saved.
	 *
	 * @return bool whether the update is successful
	 * @throws \CException if the record is new
	 */
	public function update( $attributes = null )
	{
		$_columns = array();

		if ( null === $attributes )
		{
			return parent::update( $attributes );
		}

		foreach ( $attributes as $_column => $_value )
		{
			//	column => value specified
			if ( !is_numeric( $_column ) )
			{
				$this->{$_column} = $_value;
			}
			else
			{
				//	n => column specified
				$_column = $_value;
			}

			$_columns[] = $_column;
		}

		return parent::update( $_columns );
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 *
	 * @return bool the data provider that can return the models based on the search/filter conditions.
	 */
	public function search()
	{
		$_criteria = new \CDbCriteria;

		$_criteria->compare( 'id', $this->id );
		$_criteria->compare( 'created_date', $this->created_date, true );
		$_criteria->compare( 'last_modified_date', $this->last_modified_date, true );

		return new \CActiveDataProvider(
			$this,
			array(
				 'criteria' => $_criteria,
			)
		);
	}

	/**
	 * @param string $modelClass
	 *
	 * @return BaseModel
	 */
	public function setModelClass( $modelClass )
	{
		$this->_modelClass = $modelClass;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getModelClass()
	{
		return $this->_modelClass;
	}

	//*******************************************************************************
	//* Transaction Management
	//*******************************************************************************

	/**
	 * Checks to see if there are any transactions going...
	 *
	 * @return boolean
	 */
	public function hasTransaction()
	{
		return ( null !== $this->_transaction );
	}

	/**
	 * Begins a database transaction
	 *
	 * @throws \CDbException
	 * @return \CDbTransaction
	 */
	public function transaction()
	{
		if ( $this->hasTransaction() )
		{
			throw new \CDbException( 'Cannot start new transaction while one is in progress.' );
		}

		return $this->_transaction = static::model()->getDbConnection()->beginTransaction();
	}

	/**
	 * Commits the transaction at the top of the stack, if any.
	 *
	 * @throws \CDbException
	 */
	public function commit()
	{
		if ( $this->hasTransaction() )
		{
			$this->_transaction->commit();
		}
	}

	/**
	 * Rolls back the current transaction, if any...
	 *
	 * @throws \CDbException
	 */
	public function rollback( Exception $exception = null )
	{
		if ( $this->hasTransaction() )
		{
			$this->_transaction->rollback();
		}

		//	Throw it if given
		if ( null !== $exception )
		{
			throw $exception;
		}
	}

	//*******************************************************************************
	//* REST Methods
	//*******************************************************************************

	/**
	 * A mapping of attributes to REST attributes
	 *
	 * @return array
	 */
	public function restMap()
	{
		return array();
	}

	/**
	 * If a model has a REST mapping, attributes are mapped an returned in an array.
	 *
	 * @return array|null The resulting view
	 */
	public function getRestAttributes()
	{
		$_map = $this->restMap();

		if ( empty( $_map ) )
		{
			return null;
		}

		$_results = array();
		$_columns = $this->getSchema();

		foreach ( $this->restMap() as $_key => $_value )
		{
			$_attributeValue = $this->getAttribute( $_key );

			//	Apply formats
			switch ( $_columns[$_key]->dbType )
			{
				case 'date':
				case 'datetime':
				case 'timestamp':
					//	Handle blanks
					if ( null !== $_attributeValue && $_attributeValue != '0000-00-00' && $_attributeValue != '0000-00-00 00:00:00' )
					{
						$_attributeValue = date( 'c', strtotime( $_attributeValue ) );
					}
					break;
			}

			$_results[$_value] = $_attributeValue;
		}

		return $_results;
	}

	/**
	 * Sets the values in the model based on REST attribute names
	 *
	 * @param array $attributeList
	 *
	 * @return BaseDspModel
	 */
	public function setRestAttributes( array $attributeList = array() )
	{
		$_map = $this->restMap();

		if ( !empty( $_map ) )
		{
			foreach ( $attributeList as $_key => $_value )
			{
				if ( false !== ( $_mapKey = array_search( $_key, $_map ) ) )
				{
					$this->setAttribute( $_mapKey, $_value );
				}
			}
		}

		return $this;
	}

	//*************************************************************************
	//* Static Helper Methods
	//*************************************************************************

	/**
	 * Executes the SQL statement and returns all rows. (static version)
	 *
	 * @param mixed   $_criteria         The criteria for the query
	 * @param boolean $fetchAssociative  Whether each row should be returned as an associated array with column names as the keys or the array keys are column indexes (0-based).
	 * @param array   $parameters        input parameters (name=>value) for the SQL execution. This is an alternative to {@link bindParam} and {@link bindValue}. If you have multiple input parameters, passing them in this way can improve the performance. Note that you pass parameters in this way, you cannot bind parameters or values using {@link bindParam} or {@link bindValue}, and vice versa. binding methods and  the input parameters this way can improve the performance. This parameter has been available since version 1.0.10.
	 *
	 * @return array All rows of the query result. Each array element is an array representing a row. An empty array is returned if the query results in nothing.
	 * @throws \CException execution failed
	 * @static
	 */
	public static function queryAll( $_criteria, $fetchAssociative = true, $parameters = array() )
	{
		if ( null !== ( $_builder = static::getDb()->getCommandBuilder() ) )
		{
			if ( null !== ( $_command = $_builder->createFindCommand( static::model()->getTableSchema(), $_criteria ) ) )
			{
				return $_command->queryAll( $fetchAssociative, $parameters );
			}
		}

		return null;
	}

	/**
	 * Convenience method to execute a query (static version)
	 *
	 * @param string $sql
	 * @param array  $parameters
	 *
	 * @return int The number of rows affected by the operation
	 */
	public static function execute( $sql, $parameters = array() )
	{
		return static::createCommand( $sql )->execute( $parameters );
	}

	/**
	 * Convenience method to get a database connection to a model's database
	 *
	 * @return \CDbConnection
	 */
	public static function getDb()
	{
		return static::model()->getDbConnection();
	}

	/**
	 * Convenience method to get a database command model's database
	 *
	 * @param string $sql
	 *
	 * @return \CDbCommand
	 */
	public static function createCommand( $sql )
	{
		return static::getDb()->createCommand( $sql );
	}

}