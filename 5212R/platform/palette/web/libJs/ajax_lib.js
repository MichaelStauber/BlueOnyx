/**
 * ajax_lib.js (fixed for modern browsers)
 *
 * Changes vs original:
 *  - Never attempts to set forbidden headers ("Connection", "Content-length").
 *  - Ensures headers are only set AFTER .open() (required by XHR).
 *  - Removes pointless setTimeout("void(0)", ...) no-ops.
 *  - Adds small safety checks so null _request won't explode.
 */
function ajax_lib() {
  //---------------------
  // Private Declarations
  //---------------------
  var _request = null;
  var _this = null;

  //--------------------
  // Public Declarations
  //--------------------
  this.GetResponseXML = function () {
    return (_request) ? _request.responseXML : null;
  };

  this.GetResponseText = function () {
    return (_request) ? _request.responseText : null;
  };

  this.GetRequestObject = function () {
    return _request;
  };

  this.post = function (Uri, Params) {
    this.InitializeRequest('POST', Uri);
    this.Commit(Params);
  };

  this.get = function (Uri) {
    this.InitializeRequest('GET', Uri);
    this.Commit(null);
  };

  this.InitializeRequest = function (Method, Uri) {
    _InitializeRequest();
    _this = this;

    if (!_request) {
      // Cannot create request object; treat as failure.
      try { _OnFailure(); } catch (e) {}
      return;
    }

    // Open first (setRequestHeader requires open() has been called)
    switch (arguments.length) {
      case 2:
        _request.open(Method, Uri);
        break;
      case 3:
        _request.open(Method, Uri, arguments[2]);
        break;
      default:
        // If 4+ args were provided, preserve legacy behavior:
        _request.open(Method, Uri, arguments[2], arguments[3]);
        break;
    }

    // Standard content-type for form posts (safe header)
    this.SetRequestHeader("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");

    // DO NOT set forbidden headers in browsers:
    // - "Connection" is forbidden
    // - "Content-length" is forbidden
  };

  this.SetRequestHeader = function (Field, Value) {
    if (!_request) return;

    // Guard against forbidden/unsafe headers which modern browsers refuse.
    // Keep it case-insensitive.
    var f = String(Field || '').toLowerCase();

    // Common forbidden headers for XHR/fetch (not exhaustive, but covers ours).
    if (f === 'connection' || f === 'content-length' || f === 'host' || f === 'user-agent') {
      return;
    }

    try {
      _request.setRequestHeader(Field, Value);
    } catch (e) {
      // Ignore InvalidStateError (e.g., if called before open or after send)
    }
  };

  this.Commit = function (Data) {
    if (!_request) {
      try { _OnFailure(); } catch (e) {}
      return;
    }

    try {
      _request.send(Data);
    } catch (e) {
      try { _OnFailure(); } catch (e2) {}
    }
  };

  this.Close = function () {
    if (_request) {
      try { _request.abort(); } catch (e) {}
    }
  };

  //---------------------------
  // Public Event Declarations.
  //---------------------------
  this.OnUninitialize = function () { };
  this.OnLoading = function () { };
  this.OnLoaded = function () { };
  this.OnInteractive = function () { };
  this.OnSuccess = function () { };
  this.OnFailure = function () { };

  //---------------------------
  // Private Event Declarations
  //---------------------------
  function _OnUninitialize() { _this && _this.OnUninitialize(); }
  function _OnLoading() { _this && _this.OnLoading(); }
  function _OnLoaded() { _this && _this.OnLoaded(); }
  function _OnInteractive() { _this && _this.OnInteractive(); }
  function _OnSuccess() { _this && _this.OnSuccess(); }
  function _OnFailure() { _this && _this.OnFailure(); }

  //------------------
  // Private Functions
  //------------------
  function _InitializeRequest() {
    _request = _GetRequest();
    if (_request) _request.onreadystatechange = _StateHandler;
  }

  function _StateHandler() {
    if (!_request) return;

    switch (_request.readyState) {
      case 0:
        _OnUninitialize();
        break;
      case 1:
        _OnLoading();
        break;
      case 2:
        _OnLoaded();
        break;
      case 3:
        _OnInteractive();
        break;
      case 4:
        if (_request.status === 200) {
          _OnSuccess();
        } else {
          _OnFailure();
        }
        return;
    }
  }

  function _GetRequest() {
    var obj;

    try {
      obj = new XMLHttpRequest();
    }
    catch (error) {
      try {
        obj = new ActiveXObject("Msxml2.XMLHTTP");
      }
      catch (error2) {
        try {
          obj = new ActiveXObject("Microsoft.XMLHTTP");
        }
        catch (error3) {
          return null;
        }
      }
    }
    return obj;
  }
}
