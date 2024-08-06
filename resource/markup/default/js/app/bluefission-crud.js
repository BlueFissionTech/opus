var BlueFissionCrud = function( endpoint ) {
	var _urlBase = '/api/'
	var _endpoint = endpoint || 'users';
	var _id = null;
	
	this.list = function(callback) {
		var data = {};
		this._transaction(_endpoint, data, 'GET', callback);
	};

	this.save = function(data, callback) {
		_id = ( data.id || ( data instanceof FormData && data.get('id') ) );
		if ( _id ) {
			this.update(data, callback);
		} else {
			this.add(data, callback);
		}
	};

	this.add = function(data, callback) {
		this._transaction(_endpoint, data, 'POST', callback);
	};
	this.update = function(data, callback) {
		if ( data instanceof FormData ) {
			data.append('_method', 'put');
			this._transaction(_endpoint+'/'+(data.get('id') || _id), data, 'POST', callback);
		} else {
			this._transaction(_endpoint+'/'+(data.id || _id), data, 'PUT', callback);
		}
	};
	this.remove = function(id, callback) {
		var data = {};
		this._transaction(_endpoint+'/'+id, data, 'DELETE', callback);
	};
	this.read = function(id, callback) {
		var data = {};
		this._transaction(_endpoint+'/'+id, data, 'GET', callback);
	};
	this.find = function(data, callback) {
		this._transaction(_endpoint, data, 'GET', callback);
	};
	this._transaction = function(endpoint, data, method, callback) {
		method = method || 'GET';
		endpoint = _urlBase + (endpoint || _endpoint);

		$.ajaxSetup({
		    headers: {
		        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
		    }
		});

		var payload = {
			method: method,
			url: endpoint,
			data: data,
			cache: false,
			// enctype: 'multipart/form-data',
			processData: data instanceof FormData ? false : true,
			contentType: data instanceof FormData ? false : "application/x-www-form-urlencoded; charset=UTF-8"
		};

		$.ajax(payload).done(function( msg ) {
			if ( callback ) {
				callback(msg);
			}
		}).fail(function( jqXHR, textStatus, errorThrown ) {
			console.log('============= AJAX ERROR =============');
			console.log(jqXHR);
			console.log(textStatus + " (" + jqXHR.status + ")");
			console.log(errorThrown);
			toastr.error( 'Error with operation - see console for details' );

		});
	};
};
