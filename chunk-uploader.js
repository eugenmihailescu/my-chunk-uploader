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
 * @version : 0.2.3-8 $
 * @commit  : 010da912cb002abdf2f3ab5168bf8438b97133ea $
 * @author  : Eugen Mihailescu eugenmihailescux@gmail.com $
 * @date    : Thu Feb 18 16:49:51 2016 UTC $
 * @file    : chunk-uploader.js $
 * 
 * @id      : chunk-uploader.js | Thu Feb 18 16:49:51 2016 UTC | Eugen Mihailescu eugenmihailescux@gmail.com $
*/



var console_print = function(str) {
	return;// comment this to enable debug messages
	if (window.console)
		console.log(str);
};

// a wrapper use to output debug messages
if (!window.console)
	window.console = function() {
		return { print : console_print };
	};
else
	window.console.print = console_print;

// wrapper for older browsers
Date.now = Date.now || function() {
	return +new Date;
};

// pad a H,M,S part with a zero when necessary
Number.prototype.padTime = function() {
	return (this < 10 ? '0' : '') + this;
};

// convert a timestamp to HH:MM:SS format
Number.prototype.toHMS = function() {
	var h = Math.floor(this / 3600);
	var m = Math.floor((this - h * 3600) / 60);
	var s = Math.floor(this % 60);
	return h.padTime() + ':' + m.padTime() + ':' + s.padTime();
};

// http://stackoverflow.com/questions/18638900/javascript-crc32
String.prototype.crc32 = function() {
	var makeCRCTable = function() {
		var c;
		var crcTable = [];
		for (var n = 0; n < 256; n++) {
			c = n;
			for (var k = 0; k < 8; k++) {
				c = ((c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1));
			}
			crcTable[n] = c;
		}
		return crcTable;
	};

	var crcTable = window.crcTable || (window.crcTable = makeCRCTable());
	var crc = 0 ^ (-1);

	for (var i = 0; i < this.length; i++) {
		crc = (crc >>> 8) ^ crcTable[(crc ^ this.charCodeAt(i)) & 0xFF];
	}

	return (crc ^ (-1)) >>> 0;
};

// http://en.wikipedia.org/wiki/XMLHttpRequest
if (typeof XMLHttpRequest === 'undefined') {
	XMLHttpRequest = function() {
		try {
			return new ActiveXObject("Msxml2.XMLHTTP.6.0");
		} catch (e) {
		}
		try {
			return new ActiveXObject("Msxml2.XMLHTTP.3.0");
		} catch (e) {
		}
		try {
			return new ActiveXObject("Microsoft.XMLHTTP");
		} catch (e) {
		}
		throw new Error("This browser does not support XMLHttpRequest.");
	};
}

function MyChunkUploader(class_signature) {

	var wait_timeout = 3600;// sec

	var server_error = false;

	this.supported = false;

	var max_chunk_size = 1048576;// 1MB

	// FEEDBACK EVENTS
	this.on_chunk_start = null;

	this.on_upload_progress = null;

	this.on_ready = null;

	this.on_error = null;

	this.on_abort = null;

	this.on_done = null;
	// --

	var slice_start;

	var eta;

	var elapsed;

	var sent_chunks;

	var sent_bytes;

	var raw_post = false;

	var send_interval = 20;// ms

	var max_parallel_chunks = 10;

	var loop;

	var start_time;

	var chunk_count;

	var url;

	var file;

	var nonce;

	var params;// used only if raw_post=false

	var is_running;

	var UPLOADER_CHUNK_SIGNATURE = ('undefined' == typeof class_signature) || ([ '', null ].indexOf() != -1) ? 'X-MyChunkUploader' : class_signature;

	var UPLOADER_RANGE_HEADER = 'Content-Range'; // the range header

	var UPLOADER_FILENAME_HEADER = 'Content-Disposition'; // filename header

	var UPLOADER_TYPE_HEADER = UPLOADER_CHUNK_SIGNATURE + '-Type';

	var UPLOADER_NONCE_HEADER = UPLOADER_CHUNK_SIGNATURE + '-Security-Nonce';

	var UPLOADER_RAW_POST_HEADER = UPLOADER_CHUNK_SIGNATURE + '-Raw-Post';

	var UPLOADER_ABORT_HEADER = UPLOADER_CHUNK_SIGNATURE + '-Abort';

	var UPLOADER_TIMEOUT_HEADER = UPLOADER_CHUNK_SIGNATURE + '-Timeout';

	this.options = { max_chunk_size : max_chunk_size,
	raw_post : raw_post,
	send_interval : send_interval,
	max_parallel_chunks : max_parallel_chunks,
	wait_timeout : wait_timeout };

	/**
	 * Creates a XMLHttpRequest object
	 * 
	 * @headers: optionally an header=value object|array, one element for each
	 *           HTTP header
	 * @nonce: optionally a string representing a security nonce to be sent to
	 *         the remote server using a special header
	 */
	this.create_xhr = function(headers, nonce) {
		var xhr = new XMLHttpRequest(), i;

		xhr.open("POST", url, true);
		xhr.setRequestHeader(UPLOADER_CHUNK_SIGNATURE, true);
		xhr.setRequestHeader(UPLOADER_RAW_POST_HEADER, this.options.raw_post);
		xhr.setRequestHeader(UPLOADER_TIMEOUT_HEADER, this.options.wait_timeout);

		if ('object' == typeof headers)
			for (i in headers)
				if (headers.hasOwnProperty(i) && -1 == [ UPLOADER_CHUNK_SIGNATURE, UPLOADER_RAW_POST_HEADER, UPLOADER_TIMEOUT_HEADER ].indexOf(i))
					xhr.setRequestHeader(i, headers[i]);

		if ('string' == typeof nonce && !headers.hasOwnProperty(UPLOADER_NONCE_HEADER)) {
			xhr.setRequestHeader(UPLOADER_NONCE_HEADER, nonce);
		}

		return xhr;
	};

	/**
	 * Send a POST request using a given XMLHttpRequest object with custom data
	 * 
	 * @xhr: A XMLHttpRequest object used to send the request
	 * @object: An Object that contains the data to be sent. The object is a
	 *          property=value list.
	 */
	this.send_xhr = function(xhr, object) {
		if ('object' != typeof xhr)
			return false;

		// raw post
		if (true === this.options.raw_post || 'object' != typeof object) {
			if ('object' == typeof object)
				xhr.send(object.data);
			else
				xhr.send();
		} else {
			var formData = new FormData(), i;

			if ('object' == typeof object)
				for (i in object)
					if (object.hasOwnProperty(i))
						formData.append(i, object[i]);

			if ('object' == typeof params)
				for (i in params)
					if (params.hasOwnProperty(i))
						formData.append(i, params[i]);

			xhr.send(formData);

			delete formData;
			formData = null;
		}

		return true;
	};

	/**
	 * Parse the XHR response for server error
	 * 
	 * @return mixed Returns false if XHR has no server error, an
	 *         {error:{message:string,code:string},data:object} otherwise
	 */
	var get_server_error = function(xhr) {
		// unknown XHR looks like app error not server error
		if ('object' != typeof xhr) {
			return false;
		}

		var data = null, result = { error : false,
		data : null };

		// parse the server response
		try {
			var json = JSON.parse(xhr.responseText);

			result.data = json;
			if (json.hasOwnProperty('success')) {
				if (!json.success) {
					result.error = { message : json.hasOwnProperty('message') ? json.message : 'unknown' };
					if (json.hasOwnProperty('code')) {
						result.error.code = json.code;
					}
				}
			}
		} catch (e) {
			result.error = { message : xhr.responseText.length ? xhr.responseText : e.message,
			code : 'get_server_error' };
		}

		return result;
	};

	/**
	 * Forwards a XMLHttpRequest error to a listening callback event
	 * 
	 * @xhr: A XMLHttpRequest object that is the source of the error
	 * @error: An object that contains the error information {message:the error
	 *         message,code:the error code,json:{name:the file name}}
	 */
	this.set_server_error = function(xhr, error) {
		server_error = error;
		is_running = false;
		if (null !== this.on_error) {

			server_error.success = false;
			if (server_error.hasOwnProperty('json'))
				server_error.json.name = file.name;
			else
				server_error.json = { name : file.name };

			this.on_error(xhr, server_error, 'server');
		}
	};

	/**
	 * Check a server response property if the upload is done
	 * 
	 * @return Returns true if the server confirms the upload is done, false
	 *         otherwise. In case of server|parsing error returns an object
	 *         {message:string,code:string}
	 */
	this.check_is_done = function(xhr, obj) {
		var result = false;

		if (obj.hasOwnProperty('done') && obj.done) {
			if ((file.size - sent_bytes > 0))
				result = { message : 'Upload of ' + file.name + ' failed (sent only ' + sent_bytes + ' out of ' + file.size + ' bytes)',
				code : 'incomplete' };
			else {
				if (null !== this.on_done) {
					result = true;
					this.on_done(xhr, start_time);
				}
			}
		}

		return result;
	};

	/**
	 * A callback function triggered when receiving the server response (on
	 * done|error)
	 */
	this.onreadystatechange = function(e) {
		if (!is_running || server_error) {
			// discard subsequent responses
			return;
		}

		if (e.readyState == 4) { // if POST DONE
			if (e.status == 200) {// on success POST request

				sent_chunks--;// decrement the queue

				var response = get_server_error(e), error = false;

				// check for server status
				if (false != response) {
					// at this point we are sure we received an error
					if (null != response.data) {
						// parse the server file response
						if (response.data.hasOwnProperty('json')) {
							var is_done = this.check_is_done(e, response.data.json);
							if ('object' == typeof is_done) {
								// incomplete download or JSON error
								error = is_done;
							} else {
								is_running = !is_done;
								error = response.error;
							}
						}
					} else {
						// it was a JSON exception
						error = response.error;
					}
				}

				// in case of error notify the UI
				if (false != error) {
					this.set_server_error(e, error);
				} else {
					if (response.data.success && response.data.json.hasOwnProperty('wait') && response.data.json.wait) {
						// we send a new request using the original
						// headers after a `wait` number of seconds
						// hopefully we get a response that gives us the
						// merged parts info
						var _this_ = this;
						setTimeout(function() {
							var wait_xhr = _this_.create_xhr(response.data.json.headers);

							wait_xhr.onreadystatechange = function() {
								_this_.onreadystatechange(wait_xhr);
							};

							var object = response.data.json.hasOwnProperty('new_nonce') ? { action : 'upload_restore_file',
							nonce : response.data.json.new_nonce } : null;

							_this_.send_xhr(wait_xhr, object);

						}, 1000 * response.data.json.wait);
					} else
					// no error; just notify the UI
					if (null !== this.on_ready) {
						this.on_ready({ sent : sent_bytes,
						total : file.size,
						elapsed : elapsed,
						file : response.data.json });
					}
				}
			} else {
				// unexpected HTTP error
				this.set_server_error(e, { message : 'Unexpected HTTP error : ' + this.statusText,
				code : e.status });
			}
		}
	};

	/**
	 * Upload a slice of the file to the remote server
	 * 
	 * @_this_ : a reference to the current instance of this class
	 */
	this.upload_slice = function(_this_) {
		var slice_end;
		var concurrent_chunks = 'undefined' != typeof _this_.options.max_parallel_chunks && _this_.options.max_parallel_chunks ? _this_.options.max_parallel_chunks : 10;

		if (false === server_error && sent_chunks < concurrent_chunks && slice_start < file.size) {

			// calculate the slice size
			if (slice_start + _this_.options.max_chunk_size > file.size)
				slice_end = file.size;
			else
				slice_end = slice_start + _this_.options.max_chunk_size;

			// prepapre Ajax async object
			var range_from = slice_start, range_to = slice_end - 1, range_size = file.size;

			var headers = {};
			headers[UPLOADER_RANGE_HEADER] = 'bytes ' + range_from + '-' + range_to + '/' + range_size;
			headers[UPLOADER_FILENAME_HEADER] = 'attachment; filename="' + file.name + '"';
			headers[UPLOADER_TYPE_HEADER] = file.type;

			var xhr = _this_.create_xhr(headers);

			// trigger the progress event
			if (null !== _this_.on_upload_progress) {
				var eventSource = xhr.upload || xhr;

				// IE8 wrapper
				var _addEventListener = function(object, type, listener, useCapture) {
					return 'function' == typeof object.addEventListener ? object.addEventListener(type, listener, useCapture) : object
							.attachEvent(type, listener);
				}
				_addEventListener(eventSource, "progress", function(e) {
					var chunk_pos = e.position || e.loaded;
					var chunk_size = e.totalSize || e.total;

					if (chunk_pos != chunk_size)
						return;

					sent_bytes += chunk_pos;

					var total = file.size;
					var percentage = total ? Math.round((sent_bytes / total) * 100) : 100;

					// calculate ETA
					if (sent_bytes) {
						elapsed = (Date.now() - start_time) / 1000;
						eta = elapsed * (total / sent_bytes - 1);
						if (eta < 0)
							eta = 0;
					}

					// trigger the progress event
					_this_.on_upload_progress({ sent : sent_bytes,
					total : total,
					percentage : percentage > 100 ? 100 : percentage,
					elapsed : elapsed,
					eta : eta });
				});
			}

			var caller = this;
			xhr.onreadystatechange = function() {
				caller.onreadystatechange(xhr);
			};

			var chunk = file.slice(slice_start, slice_end, file.type);

			// send a slice of file
			_this_.send_xhr(xhr, { chunk : chunk });

			// forced clean-up
			delete chunk;
			chunk = null;

			sent_chunks++;// increment the queue

			chunk_count++;// increment the total file chunks

			if (null !== _this_.on_chunk_start) {
				_this_.on_chunk_start({ index : chunk_count,
				range : { from : slice_start,
				to : slice_end } });
			}

			slice_start = slice_end;

			// continue with the next chunk
		}

		if (server_error || slice_start >= file.size) {

			clearInterval(loop);
		}

	};

	/**
	 * Slices a file into multiple chunks and uploads them to the specified url.
	 * 
	 * @_url: The URL where to upload the _file
	 * @_file: The File to be uploaded
	 * @_nonce: Optionally the security nonce to be sent
	 * @_params: Optionally a list of param=value items to be sent within the
	 *           POST request
	 */
	this.upload_chunked = function(_url, _file, _nonce, _params) {
		if (null == _file.toString().match(/object\s+File/i))
			throw new TypeError('Argument is not of File type');

		start_time = Date.now();
		eta = 0;

		loop = null;
		server_error = false;
		server_error_code = 0;

		slice_start = 0;
		chunk_count = 0;
		sent_chunks = 0;
		sent_bytes = 0;

		is_running = true;

		// trigger progress reset
		if (null != this.on_upload_progress) {
			this.on_upload_progress({ sent : 0,
			total : _file.size,
			percentage : 0,
			elapsed : 0,
			eta : 0 });
		}

		url = _url;
		file = _file;
		nonce = _nonce;
		params = _params;

		var loop_interval = 'undefined' != typeof this.options.send_interval && this.options.send_interval ? this.options.send_interval : 20;

		var _this_ = this;

		// create a new slice upload each 20ms
		loop = window.setInterval(function() {

			_this_.upload_slice(_this_);
		}, loop_interval);
	};

	/**
	 * Abort the current running upload
	 * 
	 * @params: Optionally a list of param=value items to be sent within the
	 *          abort POST request
	 */
	this.abort = function(params) {
		if (!is_running)
			return;

		// set the default server_error such that the upload loop exits
		server_error = { message : 'Aborted by user',
		code : 'UI',
		json : { name : file.name } };

		var headers = {};
		headers[UPLOADER_ABORT_HEADER] = true;
		headers[UPLOADER_FILENAME_HEADER] = 'attachment; filename="' + file.name + '"';

		var xhr = this.create_xhr(headers);

		var _this_ = this;
		xhr.onreadystatechange = function() {
			// notify the UI about abort
			if (this.readyState == 4 && null !== _this_.on_abort) {
				if (this.status == 200) {
					try {
						_server_error = JSON.parse(this.responseText);
						if (Object.keys(_server_error).length) {// if not empty
							_this_.on_abort(this, _server_error);
							return;
						}
					} catch (e) {
						server_error.message += '. ' + e.message;
						server_error.code = 'abort';
					}
				} else {
					server_error.message += '. However, the server abort failed with message :' + this.statusText;
					server_error.code = xhr.status;
				}

				_this_.on_abort(this, server_error);
			}
		};

		// notify the server to remove temporary chunks
		this.send_xhr(xhr, params);
	};

	// check if the browser meets the minimal requirements
	this.supported = window.File && window.FileReader && window.FileList && window.Blob && (window.Blob.prototype.slice || window.Blob.prototype.webkitSlice || window.Blob.prototype.mozSlice);

}