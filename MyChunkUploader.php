<?php
/**
 * ################################################################################
 * MyChunkUploader
 * 
 * Copyright 2016 Eugen Mihailescu <eugenmihailescux@gmail.com>
 * 
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * ################################################################################
 * 
 * Short description:
 * URL: https://github.com/eugenmihailescu/my-chunk-uploader
 * 
 * Git revision information:
 * 
 * @version : 0.2.3-25 $
 * @commit  : 6350dffbe1ca5a3ce5d19b8577143c5b828eec12 $
 * @author  : eugenmihailescu <eugenmihailescux@gmail.com> $
 * @date    : Thu Mar 17 22:33:39 2016 +0100 $
 * @file    : MyChunkUploader.php $
 * 
 * @id      : MyChunkUploader.php | Thu Mar 17 22:33:39 2016 +0100 | eugenmihailescu <eugenmihailescux@gmail.com> $
*/

namespace MyChunkUploader;


// A wrapper for looking up the $text in the current domain
if ( ! function_exists( __NAMESPACE__ . '\\_esc' ) ) {

	function _esc( $text ) {
		return function_exists( '_' ) ? _( $text ) : ( function_exists( '__' ) ? __( $text ) : $text );
	}
}

/**
 * Handling upload errors
 *
 * @class MyUploadException
 * @extends Exception
 *
 * @since 1.0
 * @version 1.0
 * @package package_name
 * @author Eugen Mihailescu
 *        
 */
class MyUploadException extends \Exception {

	public function __construct( $error_code ) {
		parent::__construct( $this->_getMessage( $error_code ), $error_code );
	}

	private function _getMessage( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE :
				$message = _esc( 'The uploaded file exceeds the upload_max_filesize directive in php.ini' );
				break;
			case UPLOAD_ERR_FORM_SIZE :
				$message = _esc( 
					'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form' );
				break;
			case UPLOAD_ERR_PARTIAL :
				$message = _esc( 'The uploaded file was only partially uploaded' );
				break;
			case UPLOAD_ERR_NO_FILE :
				$message = _esc( 'No file was uploaded' );
				break;
			case UPLOAD_ERR_NO_TMP_DIR :
				$message = _esc( 'Missing a temporary folder. Check the upload_tmp_dir directive in php.ini' );
				break;
			case UPLOAD_ERR_CANT_WRITE :
				$message = _esc( 'Failed to write file to disk' );
				break;
			case UPLOAD_ERR_EXTENSION :
				$message = _esc( 
					'A PHP extension stopped the file upload. Examining the list of loaded PHP extensions may help.' );
				break;
			default :
				$message = _esc( 'Unknown upload error' );
				break;
		}
		return $message;
	}
}

/**
 * Process the inputs as slices of an chunked uploaded file
 *
 * @class MyChunkUploader
 *
 * @since 1.0
 * @version 1.0
 * @package MyBackup
 * @author Eugen Mihailescu
 *        
 */
class MyChunkUploader {

	/**
	 * True if the client sent a RAW POST, false otherwise
	 *
	 * @see http://php.net/manual/en/reserved.variables.httprawpostdata.php
	 * @var bool
	 */
	private $_raw_post;

	/**
	 * 3-items array that stores the current chunk info.
	 *
	 * 0:chunk_start,1:chunk_end,2:file_size
	 *
	 * @var array
	 */
	private $_range;

	/**
	 * The uploaded file name
	 *
	 * @var string
	 */
	private $_filename;

	/**
	 * The request HTTP headers
	 *
	 * @var array
	 */
	private $_headers;

	/**
	 * When true the nonce is mandatory, otherwise it's optional
	 *
	 * @var bool
	 */
	private $_require_nonce = false;

	/**
	 *
	 * @var unknown
	 */
	private $_error;

	/**
	 * The path where the chunks and the final file will be stored
	 *
	 * @var string
	 */
	private $_tmp_dir;

	/**
	 * When the user aborted the upload this is true, otherwise false
	 *
	 * @var bool
	 */
	private $_abort;

	/**
	 * When true then this request was sent by the client which waited
	 * for all parts to be uploaded before they are merged.
	 *
	 * @var bool
	 */
	private $_waiting;

	/**
	 * When false then this class cannot be used for the current request
	 *
	 * @var bool
	 */
	private $_may_run;

	/**
	 * The upload file content type
	 *
	 * @var string
	 */
	private $_content_type;

	/**
	 * A callable to validate the nonce
	 *
	 * @var callable
	 */
	public $on_chk_nonce = false;

	/**
	 * A callable to create new nonce
	 *
	 * @var callable
	 */
	public $on_new_nonce = false;

	/**
	 * Callback triggered when upload is done.
	 * Can be used to process the uploaded file (eg. move to new location).
	 *
	 * @param string : the uploaded filename
	 * @return string : the new filename (the callback may change its location)
	 *        
	 * @var callback
	 */
	public $on_done;

	/**
	 * Callback triggered when querying the upload type.
	 * Can be used to return a different content-type than the one provided within HTTP headers.
	 *
	 * @param string : the upload content-type
	 * @return string : the new content-type (the callback may change its value)
	 *        
	 * @var callback
	 */
	public $on_get_type;

	function __construct( $working_dir = null ) {
		// the expected request header names
		$class_header = 'X-' . str_replace( __NAMESPACE__ . '\\', '', __CLASS__ );
		
		defined( __NAMESPACE__.'\\UPLOADER_CHUNK_SIGNATURE' ) || define( __NAMESPACE__.'\\UPLOADER_CHUNK_SIGNATURE', $class_header ); // the class signature
		$prefix = UPLOADER_CHUNK_SIGNATURE;
		
		defined( __NAMESPACE__.'\\UPLOADER_WAIT_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_WAIT_HEADER', $prefix . '-Wait' ); // the wait header name
		defined( __NAMESPACE__.'\\UPLOADER_TYPE_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_TYPE_HEADER', $prefix . '-Type' ); // the content-type header name
		defined( __NAMESPACE__.'\\UPLOADER_NONCE_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_NONCE_HEADER', $prefix . '-Security-Nonce' ); // the nonce header name
		defined( __NAMESPACE__.'\\UPLOADER_RAW_POST_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_RAW_POST_HEADER', $prefix . '-Raw-Post' ); // the raw post option header name
		defined( __NAMESPACE__.'\\UPLOADER_ABORT_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_ABORT_HEADER', $prefix . '-Abort' ); // the abort flag header name
		defined( __NAMESPACE__.'\\UPLOADER_TIMEOUT_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_TIMEOUT_HEADER', $prefix . '-Timeout' ); // the upload timeout header name
		
		defined( __NAMESPACE__.'\\UPLOADER_RANGE_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_RANGE_HEADER', 'Content-Range' ); // the range header name
		defined( __NAMESPACE__.'\\UPLOADER_FILENAME_HEADER' ) || define( __NAMESPACE__.'\\UPLOADER_FILENAME_HEADER', 'Content-Disposition' ); // the filename header name
		
		defined( __NAMESPACE__.'\\UPLOADER_TIMEOUT' ) || define( __NAMESPACE__.'\\UPLOADER_TIMEOUT', 3600 ); // how long to wait to merge files
		                                                                     
		// these are our custom HTTP headers
		$uploader_headers = array( 
			UPLOADER_CHUNK_SIGNATURE, 
			UPLOADER_TYPE_HEADER, 
			UPLOADER_NONCE_HEADER, 
			UPLOADER_RAW_POST_HEADER, 
			UPLOADER_ABORT_HEADER, 
			UPLOADER_TIMEOUT_HEADER, 
			UPLOADER_RANGE_HEADER, 
			UPLOADER_FILENAME_HEADER, 
			UPLOADER_WAIT_HEADER );
		
		$this->on_done = null;
		
		$this->on_get_type = null;
		
		$this->_range = array();
		
		$this->_filename = null;
		
		$this->on_chk_nonce = false;
		
		$this->_new_nonce_callback = false;
		
		$this->_require_nonce = false;
		
		$this->_content_type = false; // ie. unknown
		
		if ( defined( __NAMESPACE__.'\\UPLOADER_VERIFY_NONCE_CALLBACK' ) && is_callable( UPLOADER_VERIFY_NONCE_CALLBACK ) ) {
			$this->on_chk_nonce = UPLOADER_VERIFY_NONCE_CALLBACK;
			$this->_require_nonce = defined( __NAMESPACE__.'\\UPLOADER_REQUIRES_NONCE' ) && UPLOADER_REQUIRES_NONCE;
		}
		
		$this->_tmp_dir = ! empty( $working_dir ) ? $working_dir : _sys_get_temp_dir();
		
		// add directory trailing slash
		if ( empty( $this->_tmp_dir ) || substr( $this->_tmp_dir, - 1 ) != DIRECTORY_SEPARATOR )
			$this->_tmp_dir .= DIRECTORY_SEPARATOR;
		
		_is_dir( $this->_tmp_dir ) || mk_dir( $this->_tmp_dir );
		
		// read the sent HTTP headers
		$this->_headers = $this->array_intersect_ikey( getallheaders(), array_flip( $uploader_headers ) );
		
		// check whether this HTTP request was designed for our class; if not then don't run
		$this->_may_run = $this->_strToBool( $this->_get_header_value( UPLOADER_CHUNK_SIGNATURE ) );
		
		// check whether this HTTP request sent as a response to the merge_parts function that asked the caller to wait 1sec
		$this->_waiting = $this->_get_header_value( UPLOADER_WAIT_HEADER );
		
		// check whether this HTTP request is a abort request
		$this->_abort = $this->_strToBool( $this->_get_header_value( UPLOADER_ABORT_HEADER ) );
		
		$this->_filename = $this->get_filename();
	}

	/**
	 * array_intersect_key surroagate that works like PHP built-in function
	 * except that it compares the key case insensitive and returns the key name as per $array2
	 *
	 * @param array $array1
	 * @param array $array2
	 *
	 * @return array
	 */
	private function array_intersect_ikey( $array1, $array2 ) {
		$result = array();
		foreach ( $array1 as $k1 => $v1 )
			foreach ( $array2 as $k2 => $v2 )
				if ( strtolower( $k1 ) == strtolower( $k2 ) )
					$result[$k2] = $v1;
		return $result;
	}

	private function _get_header_value( $header_name ) {
		return ! empty( $this->_headers ) && isset( $this->_headers[$header_name] ) ? $this->_headers[$header_name] : false;
	}

	/**
	 * Converts a string to boolean
	 *
	 * @param mixed $str
	 * @return boolean
	 */
	private function _strToBool( $value ) {
		return true === $value || 1 === preg_match( '/(true|on|1|yes)/i', $value );
	}

	/**
	 * Converts a filename to a string accepted by fopen function
	 *
	 * @param string $filename
	 * @return string
	 */
	private function _sanitize_file_name( $filename ) {
		$special_chars = array( 
			"?", 
			"[", 
			"]", 
			"/", 
			"\\", 
			"=", 
			"<", 
			">", 
			":", 
			";", 
			",", 
			"'", 
			"\"", 
			"&", 
			"$", 
			"#", 
			"*", 
			"(", 
			")", 
			"|", 
			"~", 
			"`", 
			"!", 
			"{", 
			"}", 
			"%", 
			"+", 
			chr( 0 ) );
		
		$filename = preg_replace( "#\x{00a0}#siu", ' ', $filename );
		$filename = str_replace( $special_chars, '', $filename );
		$filename = str_replace( array( '%20', '+' ), '-', $filename );
		$filename = preg_replace( '/[\r\n\t -]+/', '-', $filename );
		$filename = trim( $filename, '.-_' );
		
		// Split the filename into a base and extension[s]
		$parts = explode( '.', $filename );
		
		// Return if only one extension
		if ( count( $parts ) <= 2 ) {
			return $filename;
		}
		
		// Process multiple extensions
		$filename = array_shift( $parts );
		$extension = array_pop( $parts );
		
		foreach ( (array) $parts as $part ) {
			$filename .= '.' . $part;
		}
		$filename .= '.' . $extension;
		
		return $filename;
	}

	/**
	 * Clean-up the temporary parts files in case of error|abort
	 */
	public function _cleanup_parts( $filename = false ) {
		$filename || $filename = $this->_filename;
		// file_put_contents( '/tmp/clean_up', $this->_tmp_dir . PHP_EOL );
		// file_put_contents( '/tmp/clean_up', $filename . PHP_EOL, 8 );
		
		if ( _is_dir( $this->_tmp_dir ) && ! empty( $filename ) )
			foreach ( $this->_get_parts( false, false, $filename ) as $chunk_filename ) {
				if ( ! empty( $chunk_filename ) && 0 === strpos( $chunk_filename, $this->_tmp_dir ) &&
					 _is_file( $chunk_filename ) ) {
					@unlink( $chunk_filename );
					// file_put_contents( '/tmp/clean_up', 'XXX:' . $chunk_filename . PHP_EOL, 8 );
				}
			}
	}

	/**
	 * Terminates the script and outputs a JSON response
	 *
	 * @param array $array
	 */
	public function _die( $array ) {
		die( json_encode( $array, JSON_FORCE_OBJECT ) );
	}

	/**
	 * Creates an error response
	 *
	 * @param string $message The message
	 * @param string $code The error code
	 * @param bool $sys_error When true then the message|code are extracted from the last system error
	 */
	public function _set_error( $message, $code = -1, $sys_error = true ) {
		if ( $sys_error ) {
			if ( $e = error_get_last() ) {
				$message = $e['message'];
				$code = $e['type'] . ( - 1 !== $code ? '-' . $code : '' );
			}
		}
		
		empty( $message ) && $message = _esc( 'unknown' );
		
		$error = array( 
			'success' => false, 
			'message' => $message, 
			'code' => $code, 
			'json' => array( 'name' => $this->_filename ) );
		
		$this->_cleanup_parts();
		
		$this->_die( $error );
	}

	/**
	 * Copy the input file to the output location
	 *
	 * @param string $input_file
	 * @param string $output_file
	 * @param string $sufix The error code sufix
	 * @param string $write_mode The output stream type access
	 */
	private function _copy_file( $input_file, $output_file, $sufix = '', $write_mode = 'wb' ) {
		$fr = @fopen( $input_file, 'rb' );
		( false !== $fr ) || $this->_set_error( null, "3.4$sufix" );
		
		$fw = @fopen( $output_file, $write_mode );
		( false !== $fw ) || $this->_set_error( false, "3.5$sufix" );
		
		$written = 0;
		while ( ! feof( $fr ) && ( $buffer = @fread( $fr, 4096 ) ) && ( false !== $written ) ) {
			$written = @fwrite( $fw, $buffer );
		}
		
		( false !== $buffer ) || $this->_set_error( null, "3.6$sufix" );
		( false !== $written ) || $this->_set_error( null, "3.7$sufix" );
		
		@fclose( $fr ) || $this->_set_error( null, "3.8$sufix" );
		
		@fclose( $fw ) || $this->_set_error( null, "3.9$sufix" );
	}

	/**
	 * check if the request has sent some headers
	 */
	private function _validate_headers() {
		// check the security nonce
		if ( is_callable( $this->on_chk_nonce ) ) {
			$nonce = $this->_get_header_value( UPLOADER_NONCE_HEADER );
			if ( $nonce ) {
				if ( ! call_user_func( $this->on_chk_nonce, $nonce ) ) {
					$this->_set_error( _esc( 'Security nonce error' ), "3.0.a", false );
				}
			} else {
				$this->_set_error( _esc( 'Security nonce is required' ), "3.0.b", false );
			}
		}
		
		$this->_content_type = $this->_get_header_value( UPLOADER_TYPE_HEADER );
		
		$header_error = _esc( '%s header expected' );
		
		if ( $this->_filename ) {
			
			// overwrite the old file
			_is_file( $this->_filename ) && unlink( $this->_filename );
			
			$this->_raw_post = $this->is_raw_post();
		} else {
			$this->_set_error( sprintf( $header_error, UPLOADER_FILENAME_HEADER ), 3.1, false );
		}
		
		// we expect a header that provides the uploaded chunk range
		if ( ! $this->_abort ) {
			( $this->_range = $this->get_range() ) ||
				 $this->_set_error( sprintf( $header_error, UPLOADER_RANGE_HEADER ), 3.2, false );
		}
	}

	private function _merge_files() {
		$files = $this->_get_parts();
		
		// due to parallell chunked uploads it is possible that the last chunk gets uploaded earlier than its siblings
		
		if ( $this->has_not_received_parts() ) {
			// limit the upload wait time
			! ( $timeout = $this->_get_header_value( UPLOADER_TIMEOUT_HEADER ) ) && $timeout = 3600;
			
			// if ( time() - $wait_start < $timeout )
			{
				// exit forcebly by communicating the UI to resend a request after `wait` seconds
				// we send to UI the original request headers, such that the UI send back the request
				// later using exactly the same headers (although a new nonce may be necessary)
				$response = array( 
					'name' => basename( $this->_filename ), 
					'error' => false, 
					'done' => false, 
					'wait' => 1, 
					'headers' => array_merge( array( UPLOADER_WAIT_HEADER => true ), $this->_headers ) );
				
				if ( is_callable( $this->on_new_nonce ) )
					$response['new_nonce'] = call_user_func( $this->on_new_nonce );
				
				$this->_die( array( 'success' => true, 'json' => $response ) );
			}
			// else
			// $this->_set_error(
			// sprintf( _esc( 'Wait timeout exceeded while waiting for %d more chunk(s)' ), $count ),
			// 3.14,
			// false );
		}
		
		// concatenate all chunk files into a single final file
		foreach ( $files as $chunk_filename ) {
			$this->_copy_file( $chunk_filename, $this->_filename, 'final', 'ab' );
			unlink( $chunk_filename );
		}
		
		// notify the upload is done
		if ( is_callable( $this->on_done ) && count( $files ) ) {
			try {
				$this->_filename = call_user_func( $this->on_done, $this->_filename, $this->_tmp_dir );
			} catch ( \Exception $e ) {
				$this->_set_error( $e->getMessage(), $e->getCode(), false );
			}
		}
		
		return count( $files );
	}

	/**
	 * Get the chunked files
	 *
	 * @param bool $sort When true returns the list sorted, unsorted otherwise
	 * @param bool $desc When true and sorted returns the list sorted descendent, otherwise ascendent
	 * @param string $filename When not false use this filename, otherwise the instance filename
	 * @return array
	 */
	private function _get_parts( $sort = true, $desc = false, $filename = false ) {
		$filename || $filename = $this->_filename;
		$pattern = sprintf( '%s%s-*-*', $this->_tmp_dir, $filename );
		// file_put_contents( '/tmp/clean_up', $pattern . PHP_EOL, 8 );
		
		$parts = glob( $pattern );
		
		// file_put_contents( '/tmp/clean_up', print_r( $parts, 1 ) . PHP_EOL, 8 );
		
		$sort && usort( 
			$parts, 
			function ( $a, $b ) {
				$range_pattern = '/-(\d+)-\d+$/';
				if ( preg_match( $range_pattern, $a, $range_1 ) && preg_match( $range_pattern, $b, $range_2 ) )
					return intval( $range_1[1] ) - intval( $range_2[1] );
				else
					return ( $a < $b ? - 1 : 1 ) * ( $desc ? - 1 : 1 );
			} );
		
		return ! $parts ? array() : $parts;
	}

	/**
	 * Check whether there are some parts not yet received
	 *
	 * @return int|bool Returns 0 if there are no more parts, 1 when there is at least one part.
	 *         Returns false on error.
	 */
	private function has_not_received_parts() {
		// It's important to call _get_parts instead using a copy of its result because it (re)scans the directory!
		// It's faster to search from tail to head (thus sorting DESC) because the tail upload parts are most likely
		// to not get finished; once at least one not finished part is detected the function will return
		$parts = $this->_get_parts( true, true );
		
		// file_put_contents( '/tmp/parts', print_r( $parts, 1 ) . PHP_EOL, 8 );
		
		$get_part_by_offset = function ( $to ) use(&$parts ) {
			foreach ( $parts as $filename )
				if ( preg_match( '/-(\d+)-' . $to . '$/', $filename, $matches ) )
					return $matches[1];
			
			return false;
		};
		
		$range_pattern = '/-(\d+)-(\d+)$/';
		
		$tail = array_pop( $parts );
		
		if ( preg_match( $range_pattern, $tail, $range ) ) {
			$from = $range[1];
			$to = $range[2];
			
			if ( '0' != $from )
				while ( false != ( $from = ( $get_part_by_offset( $from - 1 ) ) ) )
					;
				
				// file_put_contents( '/tmp/parts', 'has not received parts=' . ( '0' === $from ? 0 : 1 ) . PHP_EOL, 8 );
			return '0' === $from ? 0 : 1;
		}
		return false;
	}

	/**
	 * Get the content type of the current file
	 *
	 * @return mixed|string
	 */
	private function _get_content_type() {
		// return the callback content-type, if any
		if ( is_callable( $this->on_get_type ) ) {
			try {
				return call_user_func( $this->on_get_type, $this->_filename, $this->_content_type );
			} catch ( \Exception $e ) {
				$this->_set_error( $e->getMessage(), $e->getCode(), false );
			}
		}
		
		// return the HTTP header content-type
		return $this->_content_type;
	}

	/**
	 * Process the input and save it as a chunked file
	 */
	public function run() {
		if ( ! $this->may_run() ) {
			return false;
		}
		
		$file_crc32 = function ( $filename ) {
			return hexdec( @hash_file( 'crc32b', $filename ) );
		};
		
		$this->_validate_headers();
		
		if ( $this->_abort ) {
			// file_put_contents( '/tmp/do_abort', 1 );
			$this->_set_error( _esc( 'Aborted by user' ), 'UI', false );
		}
		
		// define a temporary file name that will store the chunked data
		$tmp_filename = sprintf( '%s%s-%d-%d', $this->_tmp_dir, $this->_filename, $this->_range[1], $this->_range[2] );
		
		if ( $this->_raw_post ) {
			$chunk_filename = 'php://input';
		} else {
			if ( ! $this->_waiting ) {
				empty( $_FILES ) && $this->_set_error( _esc( 'No file sent' ), 3.11, false );
				
				$file = end( $_FILES );
				
				$sys_error = error_get_last();
				UPLOAD_ERR_OK == $file['error'] || $this->_set_error( 
					null === $sys_error ? _esc( 'File upload error. Try again' ) : $sys_error['message'], 
					null === $sys_error ? 3.12 : $sys_error['type'], 
					false );
				$chunk_filename = $file['tmp_name'];
			}
		}
		
		// dump the chunk to a temporary file
		$this->_waiting || $this->_copy_file( $chunk_filename, $tmp_filename, 'chunk' );
		
		// when the last range is completed then concatenat the chunk files
		// file_put_contents( '/tmp/request', 'checking tail...' . print_r( $this->_range, 1 ) . PHP_EOL, 8 );
		if ( ( $this->_range[2] + 1 == $this->_range[3] ) ) {
			if ( $chunks = $this->_merge_files() ) {
				$response = array( 
					'tmp_name' => realpath( $this->_filename ), 
					'name' => basename( $this->_filename ), 
					'size' => @filesize( $this->_filename ), 
					'type' => $this->_get_content_type(), 
					'error' => false, 
					'chunks' => $chunks, 
					'crc' => $file_crc32( $this->_filename ), 
					'done' => true );
			} else
				$this->_set_error( _esc( 'Could not merge the chunks' ), 3.13, false );
		} else
			$response = array( 
				'index' => count( $this->_get_parts( false ) ), 
				'tmp_name' => realpath( $tmp_filename ), 
				'name' => basename( $tmp_filename ), 
				'size' => @filesize( $tmp_filename ), 
				'error' => false, 
				'crc' => $file_crc32( $tmp_filename ), 
				'done' => false );
		
		$this->_die( array( 'success' => true, 'json' => $response ) );
	}

	/**
	 * Returns whether you may call the run function or not
	 *
	 * @return bool Return true when this library is compatible with the current HTTP request, false otherwise
	 */
	public function may_run() {
		return $this->_may_run;
	}

	/**
	 * Get the wait status
	 *
	 * @return boolean
	 */
	public function is_waiting() {
		return $this->_waiting;
	}

	/**
	 * Return true when the post is HTTP RAW, false otherwise
	 *
	 * @return boolean
	 */
	public function is_raw_post() {
		return $this->_strToBool( $this->_get_header_value( UPLOADER_RAW_POST_HEADER ) );
	}

	public function is_aborting() {
		return $this->_abort;
	}

	/**
	 * Returns the chunk range within the current request.
	 * The range elements : 1st element is the range start, 2nd is the range end and 3rd is the total filesize
	 *
	 * @param string $key
	 * @return mixed Returns an array on success when $key not specified, the value for the $key or false on error
	 */
	public function get_range( $key = null ) {
		$range_pattern = '/.*\s([^-]+)-([^\/]+)\/(\d+)$/'; // http://tools.ietf.org/html/rfc7233#page-10
		
		$header = $this->_get_header_value( UPLOADER_RANGE_HEADER );
		
		$result = $header && preg_match( $range_pattern, $header, $result ) ? $result : false;
		
		return ! ( $result && isset( $key ) ) ? $result : ( isset( $key ) ? $result[$key] : $result );
	}

	public function get_filename() {
		// we expect a header that provides the uploaded file name
		$filename_pattern = '/filename=(\\\\?)(["\'])(.+?)\1\2/'; // http://tools.ietf.org/html/rfc2183
		
		$header = $this->_get_header_value( UPLOADER_FILENAME_HEADER );
		if ( $header && preg_match( $filename_pattern, $header, $matches ) ) {
			return $this->_sanitize_file_name( $matches[3] );
		}
		return false;
	}
}

?>