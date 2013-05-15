<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Platform\Yii\Components;

use Kisma\Core\Enums\HttpMethod;
use Kisma\Core\Enums\HttpResponse;
use Kisma\Core\Utility\FilterInput;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;
use Platform\Yii\Utility\Pii;

/**
 * PlatformWebApplication
 */
class PlatformWebApplication extends \CWebApplication
{
	//*************************************************************************
	//	Constants
	//*************************************************************************

	/**
	 * @var string The allowed HTTP methods
	 */
	const CORS_ALLOWED_METHODS = 'GET, POST, PUT, DELETE, PATCH, MERGE, COPY, OPTIONS';
	/**
	 * @var string The allowed HTTP headers
	 */
	const CORS_ALLOWED_HEADERS = 'x-requested-with';
	/**
	 * @var int The default number of seconds to allow this to be cached. Default is 15 minutes.
	 */
	const CORS_DEFAULT_MAX_AGE = 900;

	//*************************************************************************
	//	Members
	//*************************************************************************

	/**
	 * @var array An indexed array of white-listed hosts (ajax.example.com or foo.bar.com or just bar.com)
	 */
	protected $_corsWhitelist = array();
	/**
	 * @var bool    If true, the CORS headers will be sent automatically before dispatching the action.
	 *              NOTE: "OPTIONS" calls will always get headers, regardless of the setting. All other requests respect the setting.
	 */
	protected $_autoAddHeaders = true;
	/**
	 * @var bool If true, adds some extended information about the request in the form of X-DreamFactory-* headers
	 */
	protected $_extendedHeaders = true;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Initialize
	 */
	protected function init()
	{
		parent::init();

		Pii::app()->onBeginRequest = array( $this, 'checkRequestMethod' );
	}

	/**
	 * Handles an OPTIONS request to the server to allow CORS and optionally sends the CORS headers
	 *
	 * @param \CEvent $event
	 */
	public function checkRequestMethod( \CEvent $event )
	{
		//	Answer an options call...
		if ( HttpMethod::Options == FilterInput::server( 'REQUEST_METHOD' ) )
		{
			header( 'HTTP/1.1 204' );
			header( 'content-length: 0' );
			header( 'content-type: text/plain' );

			$this->addCorsHeaders();

			Pii::end();
		}

		//	Auto-add the CORS headers...
		if ( $this->_autoAddHeaders )
		{
			$this->addCorsHeaders();
		}
	}

	/**
	 * @param array|bool $whitelist Set to "false" to reset the internal method cache.
	 *
	 * @return bool
	 */
	public function addCorsHeaders( $whitelist = array() )
	{
		static $_cache = array();

		//	Reset the cache before processing...
		if ( false === $whitelist )
		{
			$_cache = array();

			return true;
		}

		$_originUri = null;
		$_requestSource = $_SERVER['SERVER_NAME'];
		$_origin = trim( Option::server( 'HTTP_ORIGIN' ) );

		//	Was an origin header passed?
		if ( !empty( $_origin ) )
		{
			if ( false !== ( $_originParts = $this->_parseUri( $_origin ) ) )
			{
				$_originUri = $this->_normalizeUri( $_originParts );
				$_key = sha1( $_requestSource . $_originUri );

				//	Not in cache, check it out...
				if ( !in_array( $_key, $_cache ) )
				{
					if ( false !== $this->_allowedOrigin( $_originParts, $_requestSource ) )
					{
//						Log::debug( 'Committing origin to the CORS cache > Source: ' . $_requestSource . ' > Origin: ' . $_originUri );
						$_cache[$_key] = $_originUri;
					}
					else
					{
						Log::error( 'Unauthorized origin rejected via CORS > Source: ' . $_requestSource . ' > Origin: ' . $_originUri );

						/**
						 * No sir, I didn't like it.
						 *
						 * @link http://www.youtube.com/watch?v=VRaoHi_xcWk
						 */
						header( 'HTTP/1.1 403 Forbidden' );

						Pii::end();

						//	If end fails for some unknown impossible reason...
						return false;
					}
				}
				else
				{
					$_originUri = $_cache[$_key];
				}
			}
			else
			{
				Log::warning( 'Unable to parse received origin: [' . $_origin . ']' );
			}
		}
		else
		{
//			Log::debug( 'No origin header specified > Source: ' . $_requestSource );
		}

		header( 'Access-Control-Allow-Headers: ' . static::CORS_ALLOWED_HEADERS );
		header( 'Access-Control-Allow-Methods: ' . static::CORS_ALLOWED_METHODS );
		header( 'Access-Control-Max-Age: ' . static::CORS_DEFAULT_MAX_AGE );

		if ( !empty( $_originUri ) )
		{
			header( 'Access-Control-Allow-Origin: ' . $_originUri );
		}

		if ( $this->_extendedHeaders )
		{
			header( 'X-DreamFactory-Source: ' . $_requestSource );

			if ( $_origin )
			{
				if ( !empty( $this->_corsWhitelist ) )
				{
					header( 'X-DreamFactory-Full-Whitelist: ' . implode( ', ', $this->_corsWhitelist ) );
				}

				header( 'X-DreamFactory-Origin-Whitelisted: ' . preg_match( '/^([\w_-]+\.)*' . $_requestSource . '$/', $_originUri ) );
			}
		}

		return true;
	}

	/**
	 * @param array $origin     The parse_url value of origin
	 * @param array $additional Additional origins to allow
	 *
	 * @return bool
	 */
	protected function _allowedOrigin( $origin, $additional = array() )
	{
		if ( !is_array( $additional ) )
		{
			$additional = array( $additional );
		}

		foreach ( array_merge( $this->_corsWhitelist, $additional ) as $_whiteGuy )
		{
			//	All allowed?
			if ( '*' == $_whiteGuy )
			{
				return '*';
			}

			if ( false === ( $_whiteParts = $this->_parseUri( $_whiteGuy ) ) )
			{
				continue;
			}

			//	Is this origin on the whitelist?
			if ( $this->_compareUris( $origin, $_whiteParts ) )
			{
				return $this->_normalizeUri( $origin );
			}
		}

		return false;
	}

	/**
	 * @param array $first
	 * @param array $second
	 *
	 * @return bool
	 */
	protected function _compareUris( $first, $second )
	{
		return ( $first['scheme'] == $second['scheme'] ) &&
			   ( $first['host'] == $second['host'] ) &&
			   ( $first['port'] == $second['port'] );
	}

	/**
	 * @param string $uri
	 *
	 * @param bool   $normalize
	 *
	 * @return array
	 */
	protected function _parseUri( $uri, $normalize = false )
	{
		if ( false === ( $_parts = parse_url( $uri ) ) || !isset( $_parts['host'] ) )
		{
			return false;
		}

		$_protocol = ( isset($_SERVER['HTTPS'] )  && $_SERVER['HTTPS'] != 'off' ) ? 'https' : 'http';
		$_uri = array(
			'scheme' => Option::get( $_parts, 'scheme', $_protocol),
			'host'   => Option::get( $_parts, 'host' ),
			'port'   => Option::get( $_parts, 'port' ),
		);

		return $normalize ? $this->_normalizeUri( $_uri ) : $_uri;
	}

	/**
	 * @param array $parts Return from \parse_url
	 *
	 * @return string
	 */
	protected function _normalizeUri( $parts )
	{
		return
			is_array( $parts )
				?
				( isset( $parts['scheme'] ) ? $parts['scheme'] : 'http' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : null )
				:
				$parts;
	}

	/**
	 * @param array $corsWhitelist
	 *
	 * @return PlatformWebApplication
	 */
	public function setCorsWhitelist( $corsWhitelist )
	{
		$this->_corsWhitelist = $corsWhitelist;

		//	Reset the header cache
		$this->addCorsHeaders( false );

		return $this;
	}

	/**
	 * @return array
	 */
	public function getCorsWhitelist()
	{
		return $this->_corsWhitelist;
	}

	/**
	 * @param boolean $autoAddHeaders
	 *
	 * @return PlatformWebApplication
	 */
	public function setAutoAddHeaders( $autoAddHeaders = true )
	{
		$this->_autoAddHeaders = $autoAddHeaders;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function getAutoAddHeaders()
	{
		return $this->_autoAddHeaders;
	}

	/**
	 * @param boolean $extendedHeaders
	 *
	 * @return PlatformWebApplication
	 */
	public function setExtendedHeaders( $extendedHeaders = true )
	{
		$this->_extendedHeaders = $extendedHeaders;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function getExtendedHeaders()
	{
		return $this->_extendedHeaders;
	}
}