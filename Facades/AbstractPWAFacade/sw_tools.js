/**
 * ServiceWorker toolbox for caching POST requests and other non-standard tasks.
 * 
 * Available as swTools under to global scope if imported.
 * 
 * Dependencies:
 * - EcmaScript 6
 * - Dexie (IndexedDB wrapper)
 * 
 * Example in ServiceWorker.js
 * 
-----------------------------------------------------------
importScripts('exface/vendor/npm-asset/workbox-sw/build/workbox-sw.js');
importScripts('exface/vendor/npm-asset/dexie/dist/dexie.min.js');
importScripts('exface/vendor/exface/Core/Facades/AbstractPWAFacade/sw_tools.js');

workbox.routing.registerRoute(
    /.*\/api\/jeasyui.* /i,
    swTools.strategies.POSTNetworkFirst(),
	'POST'
);
-----------------------------------------------------------
 * 
 * @author Andrej Kabachnik
 */

// Delete old buggy sw-tools IndexedDB if it exists when ServiceWorker is updated
if (this instanceof ServiceWorkerGlobalScope) {
	this.addEventListener("install", (event) => {
		Dexie.exists('sw-tools')
		.then(function(exists) {
			if (exists) {
				Dexie.delete('sw-tools');
			}
		}).catch(function (error) {
			console.error("Failed to check if legacy DB 'sw-tools' exists", error);
		});
	});
}

const swTools = {
	/**
	 * Serializes a Request into a plain JS object.
	 * 
	 * Source: https://github.com/mozilla/serviceworker-cookbook/blob/master/request-deferrer/service-worker.js
	 * 
	 * @param request
	 * @returns Promise
	 */ 
	serializeRequest: function (request) {
		  var serialized = {
		    url: request.url,
		    headers: swTools.serializeHeaders(request.headers),
		    method: request.method,
		    mode: request.mode,
		    credentials: request.credentials,
		    cache: request.cache,
		    redirect: request.redirect,
		    referrer: request.referrer
		  };
		
		  // Only if method is not `GET` or `HEAD` is the request allowed to have body.
		  if (request.method !== 'GET' && request.method !== 'HEAD') {
		    return request.clone().text().then(function(body) {
		      serialized.body = body;
		      return Promise.resolve(serialized);
		    });
		  }
		  return Promise.resolve(serialized);
	},

	/**
	 * Creates a Request from it's serialized version.
	 * 
	 * @param data
	 * @returns Promise
	 */ 
	deserializeRequest: function (data) {
		return Promise.resolve(new Request(data.url, data));
	},
	
	/**
	 * Serializes a Response into a plain JS object
	 * 
	 * @param response
	 * @returns Promise
	 */ 
	serializeResponse: function (response) {
		  var serialized = {
		    headers: swTools.serializeHeaders(response.headers),
		    status: response.status,
		    statusText: response.statusText
		  };
		
		  return response.clone().text().then(function(body) {
		      serialized.body = body;
		      return Promise.resolve(serialized);
		  });
	},
	
	serializeHeaders: function(headers) {
		var serialized = {};
		// `for(... of ...)` is ES6 notation but current browsers supporting SW, support this
		// notation as well and this is the only way of retrieving all the headers.
		for (var entry of headers.entries()) {
		    serialized[entry[0]] = entry[1];
		}
		return serialized
	},

	/**
	 * Creates a Response from it's serialized version
	 * 
	 * @param data
	 * @returns Promise
	 */ 
	deserializeResponse: function (data) {
		return Promise.resolve(new Response(data.body, data));
	},
	
	/**
	 * Cache API
	 */
	cache: {
		
		/**
		 * Saves the given request-response-pair in the cache.
		 * 
		 * @param request
		 * @param response
		 * 
		 * @return Promise
		 */
		put: function(oRequestSerialized, oResponseSerialized) {
			var sRequest = JSON.stringify(oRequestSerialized);
			var iHash = swTools.hash(sRequest);
			var entry = {
				timestamp: Date.now(),
				hash: iHash,
				request: sRequest,
				response: JSON.stringify(oResponseSerialized)
			};
			swTools._dexie.cache
			.where('hash').equals(iHash)
			.toArray()
			.then(aHits => {
				for (var oHit of aHits) {
					if (sRequest === oHit.request) {
						return swTools._dexie.cache.update(oHit.id, entry);
					}
				}
				return swTools._dexie.cache.add(entry);
			})
			.catch(e => {
				console.warn('Failed to save offline cache for request', e);
				return Promise.reject();
			});
		},
		
		/**
		 * Returns the cached response for the given request or undefined for a cache miss.
		 * 
		 * @param request
		 * 
		 * @return Promise
		 */
		match: function(oRequestSerialized) {
			var sRequest = JSON.stringify(oRequestSerialized);
			var iHash = swTools.hash(sRequest);
			return swTools._dexie.cache
			.where('hash').equals(iHash)
			.toArray()
			.then(function(aHits){
				if (aHits.length === 0) {
					return null;
				}
				for (var oHit of aHits) {
					if (oHit.request === sRequest) {
						return JSON.parse(oHit.response);
					}
				}
				return null;
			})
			.catch(e => {
				console.warn('Failed to save offline cache for request', e);
				return null;
			});
		}, 
	},

	hash: function (string){
		/*
		var h = 0, l = s.length, i = 0;
		if ( l > 0 )
		  while (i < l)
			h = (h << 5) - h + s.charCodeAt(i++) | 0;
		return h;
		*/
		var hash = 0;
		for (var i = 0; i < string.length; i++) {
			var code = string.charCodeAt(i);
			hash = ((hash<<5)-hash)+code;
			hash = hash & hash; // Convert to 32bit integer
		}
		return hash;
	},
	
	_dexie: function(){
		var db = new Dexie("workbox-postcache");
        db.version(1).stores({
            cache: '++id,timestamp,hash'
        });
        return db;
	}(),
	
	/**
	 * Custom workbox strategies
	 */
	strategies: {

		/**
		 * This strategy allows to handle POST requests via NetworkFirst
		 * 
		 * @param {Object} options 
		 * @returns 
		 */
		POSTNetworkFirst: (options) => {
			if (! options) {
				options = {};
			}
			
			return ({url, event, params}) => {
			    // Try to get the response from the network
				return Promise.resolve(
					fetch(event.request.clone())
					.then(function(response) {
						var oResponseClone = response.clone();
						if (event.request.headers.get('X-Offline-Strategy') === 'NetworkFirst') {
							swTools
							.serializeRequest(event.request.clone())
							.then(oRequestSerialized => {
								var sRequest = JSON.stringify(oRequestSerialized) || '';
								var iLength = sRequest.length;
								if (iLength !== null && iLength !== undefined && iLength <= 1*1000*1000) {
									swTools
									.serializeResponse(oResponseClone)
									.then(oResponseSerialized => {
										return swTools.cache.put(oRequestSerialized, oResponseSerialized);
									})
									.catch(e => {
										console.warn('Failed to save offline cache for ' + url, e);
									});
								}
							})
							.catch(e => {
								console.warn('Failed to save offline cache for ' + url, e);
							});
						}
						return response;
				    })
					.catch(function() {
						return swTools
						.serializeRequest(event.request.clone())
						.then(oRequestSerialized => {
							return swTools.cache.match(oRequestSerialized);
						})
						.then(oResponseSerialized => {
							if (oResponseSerialized) {
								return swTools.deserializeResponse(oResponseSerialized);
							} 
							return new Response('', {status: 503, statusText: 'Service Unavailable'});
						})
						.catch(e => {
							console.warn('Failed to check offline cache for ' + url, e);
							return new Response('', {status: 503, statusText: 'Service Unavailable'});
						});
					})
				);
			}
		},

		POSTCacheOnly: (options) => {
			if (! options) {
				options = {};
			}
			
			return ({url, event, params}) => {
			    return swTools
				.serializeRequest(event.request.clone())
				.then(oRequestSerialized => {
					return swTools.cache.match(oRequestSerialized);
				})
				.then(oResponseSerialized => {
					if (oResponseSerialized) {
						return swTools.deserializeResponse(oResponseSerialized);
					} 
					return new Response('', {status: 503, statusText: 'Service Unavailable'});
				})
			}
		},

		/**
		 * This strategy swichtes between two specified strategies depending on whether it
		 * concideres to be offline or online.
		 * 
		 * @param {{offlineStrategy: object, onlineStrategy: object}} options 
		 * @returns 
		 */
		SemiOfflineSwitch: (options) => {
			if (!options) {
				options = {};
			}           
			var mOfflineStrategy = options.offlineStrategy;
			var mOnlineStrategy = options.onlineStrategy;

			if (mOfflineStrategy === undefined) {
				throw {
					message:  'No offline strategy defined for semiOffline switch!'
				};
			}
			if (mOnlineStrategy === undefined) {
				throw {
					message:  'No online strategy defined for semiOffline switch!'
				};
			}
			
			return async ({ event, request, ...params }) => {
				var oNetStat;
				var mStrategy;
				try {
					// Make sure to load a fresh connections status instead of doing exfPWA.isOnline(), which
					// might use cached values and may also load asynchronously when startig up.
					oNetStat = await exfPWA.getConnectionStatus();
					mStrategy = oNetStat.isOfflineVirtually() ? mOfflineStrategy : mOnlineStrategy;
				} catch (error) {
					mStrategy = mOnlineStrategy;
					console.warn('Error checking network status:', error);
				}

				if (mStrategy.handle !== undefined) {
					return mStrategy.handle({ event, request, ...params });
				} else {
					return mStrategy({ event, request, ...params });
				}
			};
		} 
	}
}