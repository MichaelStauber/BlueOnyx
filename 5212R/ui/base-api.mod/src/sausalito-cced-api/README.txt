CCEd-NG API:
============

Implemented API Calls:
======================

Command       Covered         Notes
AUTH            ✅           With rate limiting and logging   (Only from localhost)
AUTHKEY         ✅           With session validation          (Only from localhost)
WHOAMI          ✅           Maps session to user OID
GET             ✅           Supports optional namespace
SET             ✅           Supports optional namespace
FIND            ✅           Supports arbitrary key/value args
CREATE          ✅           Supports key/value args
DESTROY         ✅           Basic object destruction
NAMES           ✅           Class or OID supported
BYE             ✅           Sent at the end of every transaction
ENDKEY          ✅           Session teardown
CLASSES         ✅           Returns all known class names
SUSPEND         ✅           Requires appropriate ACL (done in backend)
RESUME          ✅           Same as above
BEGIN           ✅           Transaction batching supported
COMMIT          ✅           End DTS, execute queued operations

FINDX           ✅           Added as new integrated function
GETOBJECT       ✅           Added as new integrated function
GETALL          ✅           Added as new integrated function
LOGIN           ✅           Added as new integrated function (Token/Secret Auth - only for remote access from whitelisted IPs)

Not (Yet?) Implemented:
========================

Scalar handling        🟢        Medium  

PHP handles this with &val1&val2& and the API does not yet interpret or convert these. Question is: Should it?
In Go we can do this much faster and could directly return arrays instead of Scalars, saving on post-processing
conversion in the GUI. BUT: It would cause breakage with existing GUI pages which often after fetching CODB
data run post-processing of $cce->scalar_to_array() over individual data fields.

So? Long term? We might possibly do it. But probably not.


Scratchpad for missing implementations:
=======================================

Missing implementations: 

- EVENT                🟡        Needs to go into the new CCEd replacement and has no place of being in the API


🛠 Optional Nice-to-Haves:
==========================

Feature                       Why?                                  Difficulty
-------                       ----                                  -----------
Event handling (CSCP 101)     If used by clients                    Medium
Rollback flag (CSCP 111)      Helps UI respond to changes           Low-Medium


Debuging:
=========

journalctl -u cced-api -f

In /etc/cced-api/config/cced-api.conf:

logging = true
debuglog = false


USAGE EXAMPLES:
================


AUTH:
-----

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{                   
  "cmd": "AUTH",
  "user": "admin",
  "password": "PASSWORD"
}' | jq

Response (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
  }
}

Response (fail):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}


AUTHKEY:
--------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "AUTHKEY",
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Response (success):

{
  "status": 201,
  "message": "GOODBYE"
}

Response (failure):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}


WHOAMI:
-------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "WHOAMI",
  "user": "admin",
  "password": "PASSWORD"
}' | jq

Response (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "oid": "6"
  }
}

Response (fail):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 401,
        "message": "FAIL"
      }
    ],
    "oid": "-1"
  }
}


A simple GET Request:
=======================

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{                   
  "cmd": "GET 6",
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Response (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "DATA": {
      "CLASS": "User",
      "CLASSVER": "1.0",
      "ChorizoStyle": "%7B%22theme_switcher_php-style%22%3A%22theme_blue.css%22%2C%22layout_switcher_php-style%22%3A%22layout_fixed.css%22%2C%22nav_switcher_php-style%22%3A%22switcher.css%22%2C%22skin_switcher_php-style%22%3A%22skin_light.css%22%2C%22bg_switcher_php-style%22%3A%22switcher.css%22%7D",
      "ElmerStyle": "%7B%22header_color%22%3A%22theme-6-active%22%2C%22primaryColor%22%3A%22pimary-color-blue%22%2C%22css%22%3A%22style.css%22%7D",
      "NAMESPACE": "",
      "OID": "6",
      "capLevels": "",
      "capabilities": "",
      "crypt_password": "XXXX",
      "desc_readonly": "0",
      "description": "",
      "emailDisabled": "0",
      "enabled": "1",
      "ftpDisabled": "0",
      "fullName": "Administrator",
      "gui_theme": "elmer",
      "gui_theme_timer": "0",
      "localePreference": "en_US",
      "md5_password": "XXXX",
      "name": "admin",
      "noFileCheck": "0",
      "password": "",
      "shell": "",
      "site": "",
      "sortName": "",
      "stylePreference": "BlueOnyx",
      "systemAdministrator": "1",
      "uiRights": "",
      "ui_enabled": "1",
      "volume": "/home"
    }
  }
}

Response (fail):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 300,
        "message": "UNKNOWN OBJECT 9999"
      },
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}

Simple GET <OBJ> . <NAMESPACE>:
================================

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{                   
  "cmd": "GET 6 . Email",                 
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Response (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "DATA": {
      "CLASSVER": "1.1",
      "NAMESPACE": "Email",
      "aliases": "",
      "do_not_reply": "",
      "do_not_reply_from": "",
      "forwardEmail": "",
      "forwardEnable": "0",
      "forwardSave": "0",
      "vacationMsg": "",
      "vacationMsgStart": "1747173300",
      "vacationMsgStop": "1747173300",
      "vacationOn": "0"
    }
  }
}

Response (fail):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 303,
        "message": "UNKNOWN NAMESPACE fluxcompensator"
      },
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}


A simple FIND Request:
=======================

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "FIND Vsite name = \"site1\"",
  "oid": "",
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "oidlist": [
      "34"
    ]
  }
}

Result (fail):

{
  "status": 201,
  "message": "GOODBYE"
}


NAMES:
======

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "NAMES 6",
  "oid": "34",
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "namespaces": [
      "Shell",
      "Email",
      "subdomains",
      "Radicale",
      "Disk",
      "SSH",
      "Sites",
      "RootAccess",
      "ImapSync"
    ]
  }
}

Result (fail):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 300,
        "message": "UNKNOWN OBJECT 999"
      },
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}


Classes:
========

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "CLASSES",
  "oid": "",
  "user": "admin",
  "sessionid": "tK46aZ885HA1QxEiti1dlq.....VpQM3cfavn1yxfF8vFEBeoH3"
}' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "classes": [
      "pam_abl_settings",
      "FtpSite",
      "Vsite",
      "VsiteServices",
      "WebApplications",
      "EmailAlias",
      "CapabilityGroup",
      "VirtualHost",
      "SOL_Console",
      "SWUpdateServer",
      "ProtectedEmailAlias",
      "DnsSOA",
      "Network",
      "PHP",
      "Package",
      "UserServices",
      "MySQL",
      "Capabilities",
      "User",
      "Disk",
      "dnsbl",
      "DnsSlaveZone",
      "Schedule",
      "Subdomains",
      "ServiceQuota",
      "IPPoolingRange",
      "ActiveMonitor",
      "Shop",
      "WebApplicationsList",
      "DnsRecord",
      "Workgroup",
      "UserExtraServices",
      "System",
      "mx2",
      "Route"
    ]
  }
}

FINDX:
======

Simple - no regex:
------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "FINDX",
  "class": "Vsite",
  "args": {
    "domain": "smd.net"
  },
  "sorttype": "ascii",
  "sortprop": "fullName",
  "user": "admin",
  "sessionid": "EqSjRR2CawGMPVmHFvZUND75p15I9gFCxlLc0BE0VaAAVK5o4V8Hm5CCWv8UBDm"
}' | jq

Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "oidlist": [
      "34"
    ]
  }
}

Result (fail):

{
  "status": 201,
  "message": "OK",
  "data": {
    "oidlist": []
  }
}

Complex - with regex:
----------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "FINDX",
  "class": "Vsite",
  "args": {
    "domain": "smd.net"
  },
  "regex_args": {
    "fqdn": "^t1\\.smd\\.net$"
  },
  "sorttype": "ascii",
  "sortprop": "fullName",
  "user": "admin",
  "sessionid": "EqSjRR2CawGMPVmHFvZUND75p15I9gFCxlLc0BE0VaAAVK5o4V8Hm5CCWv8UBDm"
}' | jq

Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "oidlist": [
      "34"
    ]
  }
}

Result (fail):

{
  "status": 201,
  "message": "OK",
  "data": {
    "oidlist": []
  }
}


GETOBJECT:
===========

Simple (no args):
------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "GETOBJECT",
  "class": "System",
  "args": {},
  "namespace": "",
  "user": "admin",
  "sessionid": "EqSjRR2CawGMPVmHFvZUND75p15I9gFCxlLc0BE0VaAAVK5o4V8Hm5CCWv8UBDm"
}' | jq

Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "DATA": {
      "allowed_themes": "both",
      [...]
      "productlanguage": "en_US",
      "productname": "BlueOnyx 5211R",
      "productserialnumber": "",
      "productvendor": "",
      "serialnumber": ""
    }
  }
}

Result (fail):

{
  "status": 404,
  "message": "Object not found"
}

Complex (with args):
---------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "GETOBJECT",
  "class": "Vsite",
  "args": { "name": "site1" },
  "namespace": "",
  "user": "admin",
  "sessionid": "EqSjRR2CawGMPVmHFvZUND75p15I9gFCxlLc0BE0VaAAVK5o4V8Hm5CCWv8UBDm"
}' | jq


Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "DATA": {
      "basedir": "/home/.sites/site1",
      "class": "Vsite",
      "classver": "1.0",
      "createduser": "admin",
      "dns_auto": "1",
      "domain": "smd.net",
      "emaildisabled": "0",
      "force_update": "1747164973",
      "fqdn": "t1.smd.net",
      "hostname": "t1",
      "ipaddr": "208.77.151.218",
      "ipaddripv6": "",
      "mailaliases": "",
      "mailcatchall": "",
      "maxusers": "25",
      "name": "site1",
      "namespace": "",
      "oid": "34",
      "prefix": "",
      "site_preview": "0",
      "siteadmincaps": "",
      "suspend": "0",
      "userprefixenabled": "0",
      "userprefixfield": "",
      "userwebsdisabled": "0",
      "volume": "/home",
      "webaliases": "",
      "webaliasredirects": "1"
    }
  }
}


Result (fail):

{
  "status": 404,
  "message": "Object not found"
}

GETALL:
========

Simple (we directly pass the OIDs we want):
--------------------------------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "GETALL",
  "oids": ["56", "58"],
  "user": "admin",
  "sessionid": "EqSjRR2CawGMPVmHFvZUND75p15I9gFCxlLc0BE0VaAAVK5o4V8Hm5CCWv8UBDm"
}' | jq

Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "objects": {
      "56": {           // <--- First OID with all NameSpaces
        "Disk": {
          [...]
        },
        "Email": {
          [...]
        },
        "ImapSync": {
          [...]
        },
        "OBJECT": {
          [...]
        },
        "Radicale": {
          [...]
        },
        "RootAccess": {
          [...]
        },
        "SSH": {
          [...]
        },
        "Shell": {
          [...]
        },
        "Sites": {
          [...]
        },
          [...]
        }
      },
      "58": {           // <--- Second OID with all NameSpaces
        "Disk": {
          [...]
        },
        "Email": {
          [...]
        },
          [...]
        },
        "OBJECT": {
          [...]
        },
        "Radicale": {
          [...]
        },
        "RootAccess": {
          [...]
        },
        "SSH": {
          [...]
        },
        "Shell": {
          [...]
        },
        "Sites": {
          [...]
        },
        "subdomains": {
          [...]
        }
      }
    }
  }
}

Result (fail):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 401,
        "message": "FAIL"
      }
    ],
    "oid": "-1"
  }
}


Query (we specify selection criterias):
----------------------------------------

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "GETALL",
  "class": "User",
  "args": {
    "site": "site1"
  },
  "user": "admin",
  "sessionid": "XkK41qcF4GqOOmSNAvGyKVjAyuwf5y6QJGIyUJ4NdkrRw7uVqYj0Jrr7LNaF96j"
}' | jq


Result (success):

{
  "status": 201,
  "message": "OK",
  "data": {
    "objects": {
      "56": {           // <--- First OID with all NameSpaces
        "Disk": {
          [...]
        },
        "Email": {
          [...]
        },
        "ImapSync": {
          [...]
        },
        "OBJECT": {
          [...]
        },
        "Radicale": {
          [...]
        },
        "RootAccess": {
          [...]
        },
        "SSH": {
          [...]
        },
        "Shell": {
          [...]
        },
        "Sites": {
          [...]
        },
          [...]
        }
      },
      "58": {           // <--- Second OID with all NameSpaces
        "Disk": {
          [...]
        },
        "Email": {
          [...]
        },
          [...]
        },
        "OBJECT": {
          [...]
        },
        "Radicale": {
          [...]
        },
        "RootAccess": {
          [...]
        },
        "SSH": {
          [...]
        },
        "Shell": {
          [...]
        },
        "Sites": {
          [...]
        },
        "subdomains": {
          [...]
        }
      }
    }
  }
}

Result (fail):

{
  "status": 201,
  "message": "OK",
  "data": {
    "objects": {}
  }
}

SET transaction:
================

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "SET 1 . Snmp readCommunity=\"public\" readWriteCommunity=\"private\" enabled=\"0\"",
  "user": "admin",
  "sessionid": "NvDN4sYQvT8wMlW8tK413ZOZT5BDe5mPoQsi6eWsWVMy4yvn6pf0cTNVNeoP7Av"
}' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE"
}

Result (fail):

{
  "status": 401,
  "message": "GOODBYE",
  "data": {
    "errors": [
      {
        "code": 302,
        "message": "BAD DATA 1 enabled \"[[base-cce.invalidData]]\""
      },
      {
        "code": 401,
        "message": "FAIL"
      }
    ]
  }
}


PING:
=====

curl -k -s -X POST https://127.0.0.1:9092/v2/cce -d '{
  "cmd": "PING" 
}' | jq

Result (success):

{
  "message": "PONG",
  "status": 202
}

Result (fail):

No answer, because then the API service is dead or unresponsive. 


Delayed Transaction Support (DTS):
===================================

BEGIN (starts recording):
-------------------------

curl -sk -X POST https://127.0.0.1:9092/v2/cce \
  -H "Content-Type: application/json" \
  -d "{\"cmd\":\"BEGIN\", \"sessionid\":\"$SESSION_ID\", \"user\":\"admin\"}"


Result:

{"status":201,"message":"BEGIN OK"}

Qeue up commands:
-----------------

curl -sk -X POST https://127.0.0.1:9092/v2/cce \
  -H "Content-Type: application/json" \
  -d "{\"cmd\":\"SET\", \"oid\":\"56\", \"args\":{\"description\":\"Testing\"}, \"sessionid\":\"$SESSION_ID\", \"user\":\"admin\"}"

Result:

{"status":100,"message":"QUEUED"}

curl -sk -X POST https://127.0.0.1:9092/v2/cce \
  -H "Content-Type: application/json" \
  -d "{\"cmd\":\"SET\", \"oid\":\"58\", \"args\":{\"description\":\"Testing\"}, \"sessionid\":\"$SESSION_ID\", \"user\":\"admin\"}"

Result:

{"status":100,"message":"QUEUED"}

COMMIT (execute all commands from queue):
-----------------------------------------

curl -sk -X POST https://127.0.0.1:9092/v2/cce \
  -H "Content-Type: application/json" \
  -d "{\"cmd\":\"COMMIT\", \"sessionid\":\"$SESSION_ID\", \"user\":\"admin\"}"

Result (success):

{"status":201,"message":"COMMIT successful","data":{"results":[{"command":"SET 56 description = \"Testing\"","index":0,"message":"OK","status":201}]}}

Result (failure):

{"status":409,"message":"One or more commands in the transaction failed.","data":{"errors":[{"command":"SET 58 invalid_key = \"Testing\"","error":"Error: 302 BAD DATA 58 invalid_key \"[[base-cce.unknownAttr]]\"","index":1,"message":"BAD DATA","status":302}],"results":[{"command":"SET 56 description = \"Testing\"","index":0,"message":"OK","status":201},{"command":"SET 58 invalid_key = \"Testing\"","error":"Error: 302 BAD DATA 58 invalid_key \"[[base-cce.unknownAttr]]\"","index":1,"message":"BAD DATA","status":302}]}}


Token Based AUTH:
==================

curl -sk -X POST https://<PUBLIC-IP>:9092/v2/cce \
  -H "Content-Type: application/json" \
  -H "X-Client-Secret: AsdFs2sfG2mtVUfzF2FSbkBtp9f8CVo" \
  -d '{"cmd": "LOGIN"}' | jq


Result (success):

{
  "status": 201,
  "message": "TOKEN ISSUED",
  "data": {
    "expires": "2025-05-17T23:49:24-05:00",
    "token": "IaKCHhrATt7rp8enHNKC5jyuY8AripmELHXXVheAAKj5CfQD30SKAAAowHHLpSVW"
  }
}

Result (fail):

curl -sk -X POST https://<PUBLIC-IP>:9092/v2/cce   -H "Content-Type: application/json"   -d '{"cmd": "LOGIN"}'
Unauthorized: invalid client secret



Special notes for 'Token Based Auth':
--------------------------------------

The service 'cced-api.service' on BlueOnyx provides the CCEd-API:

[root@test ~]# systemctl status cced-api.service
● cced-api.service - CCEd API Proxy Service
     Loaded: loaded (/usr/lib/systemd/system/cced-api.service; disabled; preset: disabled)
    Drop-In: /run/systemd/system/service.d
             └─zzz-lxc-service.conf
     Active: active (running) since Sat 2025-05-17 22:48:54 -05; 4min 13s ago
    Process: 1173713 ExecStartPre=/bin/bash -c /usr/sausalito/bin/cced-api-pre.sh 2>&1 (code=exited, status=0/SUCCESS)
   Main PID: 1173775 (cced-api)
      Tasks: 9 (limit: 407768)
     Memory: 16.0M
        CPU: 318ms
     CGroup: /system.slice/cced-api.service
             └─1173775 /usr/sausalito/sbin/cced-api

On startup /usr/sausalito/bin/cced-api-pre.sh is executed BEFORE the daemon /usr/sausalito/sbin/cced-api is started. The daemon runs as 
admserv:admserv and uses the configuration from /etc/cced-api/ as shown below:

[root@test ~]# tree /etc/cced-api/
/etc/cced-api/
├── api-admin.passwd
├── certs
│   ├── ca-certs
│   ├── certificate
│   └── key
├── config
│   └── cced-api.conf
└── master.key

Config file /etc/cced-api/config/cced-api.conf:
------------------------------------------------

unix_socket_path = /usr/sausalito/cced.socket
socket_read_timeout = 300
listen_address = 0.0.0.0:9092
cert_file = /etc/cced-api/certs/certificate
key_file = /etc/cced-api/certs/key
ca_cert_file = /etc/cced-api/certs/ca-certs
enable_http = false
logging = true
debuglog = true
api_access = 191.89.131.84/32
token_lifetime = 300
api_auth_fails = 5
api_ban_time = 60


The ExecStartPre from the Systemd Unit-File cced-api.service runs /usr/sausalito/bin/cced-api-pre.sh:

-----------------------------------------------------------------------------------------------------
#!/bin/bash
set -e

mkdir -p /etc/cced-api/certs

cp -f /etc/admserv/certs/certificate /etc/cced-api/certs/ || true
cp -f /etc/admserv/certs/key /etc/cced-api/certs/ || true
cp -f /etc/admserv/certs/ca-certs /etc/cced-api/certs/ || true

chown admserv:admserv /etc/cced-api/certs/* || true

# Create and set permissions on the log file
touch /var/log/cced-api.log
chown admserv:admserv /var/log/cced-api.log
chmod 664 /var/log/cced-api.log

# Prepare empty /etc/cced-api/config/access and/or set permissions and ownerships:
touch /etc/cced-api/config/access
chown admserv:admserv /etc/cced-api/config/access
chmod 600 /etc/cced-api/config/access

# Run Constructor to create 'api-admin' account. Rotates its password on every subsequent use.
if [ -e /usr/sausalito/sbin/gen_api_admin.pl ];then
  /usr/sausalito/sbin/gen_api_admin.pl
fi
-----------------------------------------------------------------------------------------------------

This copies /etc/admserv/certs/ (unreadable by admserv:admserv!) to /etc/cced-api/certs/ so that our
cced-api service can use the same SSL certificate as AdmServ.

It also creates /etc/cced-api/config/access and ensures tight permissions and ownerships.


/etc/cced-api/config/access:
----------------------------

cced-api auto-populats this with entries like this:

191.89.131.84:AsdFs2sfG2mtVUfzF2FSbkBtp9f8CVo

Each 'api_access' IP (if not already present!) will be entered and receives a randomly generated 'Client-Secret'.



API-User Creation and Maintenance Script /usr/sausalito/sbin/gen_api_admin.pl:
--------------------------------------------------------------------------------

This script is also executed and performs the following:

1.) If not already present, user 'api-admin' will be created.
2.) User 'api-admin' receives 'systemAdministrator' privileges
3.) User 'api-admin' gets 'Shell' and 'RootAccess' denied
4.) User 'api-admin' gets a random 24 character password set.
5.) If not present, /etc/cced-api/master.key will get created
6.) The random plaintext password for 'api-admin' is stored encrypted in /etc/cced-api/api-admin.passwd
    Subsequent runs of /usr/sausalito/sbin/gen_api_admin.pl during restarts of 'cced-api.service' will
    ALWAYS trigger a password change of user 'api-admin'.
7.) GUI Login page WILL deny user 'api-admin' to login, even if plaintext password is known.
8.) API access to command LOGIN is restricted to whitelisted 'api_access' IPs WITH their auto-generated '
    Client-Secret', which is is only valid if used from that originating whitelisted IP.
9.) Tokens expire on 'BYE' or 'token_lifetime' expiry. Default: 300 seconds.


API Security Model:
====================

If accessed from localhost:
----------------------------

Commands:
---------

LOGIN     Usage of token/secret based login is denied
AUTH      Allowed, requires valid username and password
AUTHKEY   Allowed, requires valid username and unexpired and valid sessionId


If accessed from remote IPs:
-----------------------------

LOGIN     Usage of token/secret based login is allowed if whitelisted
AUTH      Access Denied: LOGIN not allowed from localhost
AUTHKEY   Access Denied: LOGIN not allowed from localhost


Scenario 1:
___________

IP address is NOT listed in /etc/cced-api/config/cced-api.conf under 'api_access':

ALL Access triggers a '403 Forbidden'


Scenario 2:
___________

IP address *IS* white-listed in /etc/cced-api/config/cced-api.conf under 'api_access':

LOGIN     Allowed: Usage of token based login with matching 'Client-Secret' is ALLOWED - grants 'systemAdministrator' access.
AUTH      Access Denied: AUTH and AUTHKEY only allowed from localhost
AUTHKEY   Access Denied: AUTH and AUTHKEY only allowed from localhost


TOKEN Usage:
=============

Username is not required, as commands will be execute as 'api-admin' once token has been set up:


Example event chain for API usuage:
------------------------------------


Request Token:
_______________


curl -sk -X POST https://<PUBLIC-IP>:9092/v2/cce   -H "Content-Type: application/json"   -H "X-Client-Secret: AsdFs2sfG2mtVUfzF2FSbkBtp9f8CVo"   -d '{"cmd": "LOGIN"}' | jq

Result (success):

{
  "status": 201,
  "message": "TOKEN ISSUED",
  "data": {
    "expires": "2025-05-18T02:49:26-05:00",
    "token": "X1w91tvZrqrZeGdjJy5dTlCgxlIKBo941lzJr2R8dYfpvoIRdEfAFAbfKV9AvBLA"
  }
}


Use Token and Client-Secret to execute command:
_______________________________________________


curl -sk -X POST https://<PUBLIC-IP>:9092/v2/cce   -H "Content-Type: application/json"   -H "X-Client-Secret: AsdFs2sfG2mtVUfzF2FSbkBtp9f8CVo"   -d '{
    "cmd": "WHOAMI",
    "token": "<TOKEN>"
  }' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE",
  "data": {
    "oid": "60"
  }
}

Send BYE:
__________


curl -sk -X POST https://<PUBLIC-IP>:9092/v2/cce   -H "Content-Type: application/json"   -H "X-Client-Secret: AsdFs2sfG2mtVUfzF2FSbkBtp9f8CVo"   -d '{
    "cmd": "BYE",
    "token": "<TOKEN>"
  }' | jq

Result (success):

{
  "status": 201,
  "message": "GOODBYE"
}


Please note: Watch token expiry!
--------------------------------

Tokens are valid for 300 seconds or until a 'BYE' is sent. 

Tokens must be requested again via LOGIN after a 'BYE' has been sent.


Revoke API Access:
==================

Two things can be done to remove/invalidate API access AFTER it once had been granted:

- Remove the IP from 'api_access'. (If you want to deny that IP any future access)
- Edit /etc/cced-api/config/access and change the 'Client-Secret' associated with the IP. (IP then still has access, but needs to use the new 'Client-Secret' for that)

The BlueOnyx GUI to edit the API config will have a page where contents of /etc/cced-api/config/access are shown and where this can be done.


