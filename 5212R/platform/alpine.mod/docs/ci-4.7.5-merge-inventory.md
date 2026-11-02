# CI 4.5.5 → 4.7.5 merge inventory (5212R alpine.mod)

**Work tree:** `BlueOnyx/tmp/5212R-Alpine-CI-update/`  
**Do not merge to `5212R/platform/alpine.mod` until explicitly approved.**  
**Compared against:** `5212R/platform/alpine.mod` (trunk CI 4.5.5 vendor)  
**Date:** 2026-11-01  

## Summary

| Item | Count / note |
|------|----------------|
| CodeIgniter | **4.5.5 → 4.7.5** |
| alpine.mod VERSION-RELEASE | **5.2-56 → 5.2-57** (this packaging bump) |
| Vendor files **removed** (must `svn delete` / delete on merge) | **51** |
| Vendor files **added** (must `svn add` on merge) | **128** |
| Test host | rain.smd.net (not builder10) |

## Merge procedure (when approved)

1. Copy/merge **changed and added** files from this tmp tree into `5212R/platform/alpine.mod`.
2. **Delete** every path listed under "Vendor files removed" (and any other `svn status` `!` / missing paths).
3. Do **not** only overlay new files — missing deletes leave stale 4.5.5 Kint/Zval paths etc.
4. Keep BX sacred overlays and `bx_ci4_fixer.sh` sidecars in sync (`.bx` / `.csrf`).
5. Rebuild/install `base-alpine` + `base-alpine-ci4-vendor` RPMs; smoke on a 5212R box.
6. Related modules in the same tmp tree (separate packages): `base-sitestats.mod` 2.4-17 (GoAccess WS proxy), docs for admserv-php prepend.


## Application / packaging files (merge carefully)

### BX sacred overlays (keep; do not overwrite with stock)
- ci4/app/Config/App.php (+ App.php.bx)
- ci4/app/Config/Autoload.php (+ Autoload.php.bx)
- ci4/app/Config/Paths.php (+ Paths.php.bx)
- ci4/app/Config/Routes.php (+ Routes.php.bx)
- ci4/app/Config/Session.php (+ Session.php.bx)  # cookie bx_session
- ci4/app/Config/Filters.php (+ Filters.php.csrf / .nocsrf)

### Modified BX application code
- ci4/app/Config/Events.php  # session numeric-key sanitize hooks
- ci4/app/Helpers/validation_helper.php  # preg_match/$data collision fix
- ci4/app/Helpers/blueonyx_helper.php  # bx_session_sanitize_numeric_keys()
- ci4/app/Helpers/uifc_ng_helper.php  # addIframe HTTP_HOST
- ci4/bx_ci4_fixer.sh  # restore App/Paths + bx_session sanity
- ci4/composer.json / composer.lock  # pin codeigniter4/framework 4.7.5

### New CI 4.7 project Config files (ADD on merge)
- ci4/app/Config/Hostnames.php
- ci4/app/Config/WorkerMode.php

### Stock Config refreshed from 4.7.5 (merged; sacred list above excluded)
Most other app/Config/*.php files were refreshed from framework 4.7.5 stock
(Feature, Kint without richSort, Routing, Security, Session shape kept BX cookie, etc.)

### Docs
- alpine.mod/docs/admserv-php-no-auto-prepend.md
- alpine.mod/docs/ci-4.7.5-merge-inventory.md (this file)

### Do NOT commit / do NOT merge into 5212R as app tree
- alpine.mod/ci4/vendor/  (duplicate; SoT is src/base-alpine-ci4-vendor/.../vendor)
- *.pre475 backup junk (deleted before commit)


## Vendor files removed (delete on merge into 5212R)

Paths relative to `src/base-alpine-ci4-vendor/base-alpine-ci4-vendor/vendor/`:

```
./codeigniter4/framework/phpunit.xml.dist
./codeigniter4/framework/system/Router/Exceptions/RedirectException.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/DOMDocumentPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/SplObjectStoragePlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/ArrayLimitPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/BlacklistPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/ClosurePlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/DepthLimitPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/MethodDefinitionPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/RecursionPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/SimpleXMLElementPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/TimestampPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/ArrayLimitPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/BlacklistPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/DepthLimitPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/EnumPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/RecursionPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/resources/compiled/microtime.js
./codeigniter4/framework/system/ThirdParty/Kint/resources/compiled/plain.js
./codeigniter4/framework/system/ThirdParty/Kint/resources/compiled/rich.js
./codeigniter4/framework/system/ThirdParty/Kint/resources/compiled/shared.js
./codeigniter4/framework/system/ThirdParty/Kint/Zval/BlobValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/ClosureValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/DateTimeValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/EnumValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/InstanceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/MethodValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/ParameterHoldingTrait.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/ParameterValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/ColorRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/MethodDefinitionRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/MicrotimeRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/Representation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/SourceRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Representation/SplFileInfoRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/ResourceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/SimpleXMLElementValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/StreamValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/ThrowableValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/TraceFrameValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/TraceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Zval/Value.php
./ratchet/pawl/.github/workflows/ci.yml
./ratchet/pawl/.gitignore
./ratchet/pawl/phpunit.xml.dist
./ratchet/pawl/phpunit.xml.legacy
./ratchet/pawl/tests/autobahn/fuzzingserver.json
./ratchet/pawl/tests/autobahn/runner.php
./ratchet/pawl/tests/unit/ConnectorTest.php
./ratchet/pawl/tests/unit/RequestUriTest.php
./react/promise/src/RejectedPromise.php
```

## Vendor files added (add on merge into 5212R)

Paths relative to `src/base-alpine-ci4-vendor/base-alpine-ci4-vendor/vendor/`:

```
./codeigniter4/framework/app/Config/Hostnames.php
./codeigniter4/framework/app/Config/WorkerMode.php
./codeigniter4/framework/app/Views/errors/html/error_400.php
./codeigniter4/framework/phpunit.dist.xml
./codeigniter4/framework/system/API/ApiException.php
./codeigniter4/framework/system/API/BaseTransformer.php
./codeigniter4/framework/system/API/TransformerInterface.php
./codeigniter4/framework/system/Cache/Handlers/ApcuHandler.php
./codeigniter4/framework/system/CLI/SignalTrait.php
./codeigniter4/framework/system/Commands/Generators/TransformerGenerator.php
./codeigniter4/framework/system/Commands/Generators/Views/transformer.tpl.php
./codeigniter4/framework/system/Commands/Translation/LocalizationSync.php
./codeigniter4/framework/system/Commands/Worker/Views/Caddyfile.tpl
./codeigniter4/framework/system/Commands/Worker/Views/frankenphp-worker.php.tpl
./codeigniter4/framework/system/Commands/Worker/WorkerInstall.php
./codeigniter4/framework/system/Commands/Worker/WorkerUninstall.php
./codeigniter4/framework/system/Database/TableName.php
./codeigniter4/framework/system/DataCaster/Cast/EnumCast.php
./codeigniter4/framework/system/Debug/Toolbar/Views/toolbarstandalone.js
./codeigniter4/framework/system/Encryption/KeyRotationDecorator.php
./codeigniter4/framework/system/Entity/Cast/EnumCast.php
./codeigniter4/framework/system/Exceptions/BadFunctionCallException.php
./codeigniter4/framework/system/Exceptions/BadMethodCallException.php
./codeigniter4/framework/system/Exceptions/InvalidArgumentException.php
./codeigniter4/framework/system/Exceptions/LogicException.php
./codeigniter4/framework/system/Exceptions/RuntimeException.php
./codeigniter4/framework/system/Files/Exceptions/ExceptionInterface.php
./codeigniter4/framework/system/Files/FileSizeUnit.php
./codeigniter4/framework/system/HTTP/Exceptions/ExceptionInterface.php
./codeigniter4/framework/system/Language/en/Api.php
./codeigniter4/framework/system/Language/en/Honeypot.php
./codeigniter4/framework/system/Router/Attributes/Cache.php
./codeigniter4/framework/system/Router/Attributes/Filter.php
./codeigniter4/framework/system/Router/Attributes/Restrict.php
./codeigniter4/framework/system/Router/Attributes/RouteAttributeInterface.php
./codeigniter4/framework/system/Router/Exceptions/ExceptionInterface.php
./codeigniter4/framework/system/Session/PersistsConnection.php
./codeigniter4/framework/system/Test/Utilities/NativeHeadersStack.php
./codeigniter4/framework/system/ThirdParty/Escaper/EscaperInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/ClassHooksPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/ClassStringsPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/DomPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/HtmlPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/PluginBeginInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/PluginCompleteInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Parser/ProfilePlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/AssetRendererTrait.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/ConstructableRendererInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/CallableDefinitionPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/LockPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Rich/ProfilePlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/LockPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/Renderer/Text/SplFileInfoPlugin.php
./codeigniter4/framework/system/ThirdParty/Kint/resources/compiled/main.js
./codeigniter4/framework/system/ThirdParty/Kint/Value/AbstractValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ArrayValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ClosedResourceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ClosureValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ColorValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/ArrayContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/BaseContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/ClassConstContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/ClassDeclaredContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/ClassOwnedContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/ContextInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/DoubleAccessMemberContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/MethodContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/PropertyContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Context/StaticPropertyContext.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/DateTimeValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/DeclaredCallableBag.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/DomNodeListValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/DomNodeValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/EnumValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/FixedWidthValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/FunctionValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/InstanceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/MethodValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/MicrotimeValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ParameterBag.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ParameterHoldingTrait.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/AbstractRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/BinaryRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/CallableDefinitionRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/ColorRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/ContainerRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/MicrotimeRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/ProfileRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/RepresentationInterface.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/SourceRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/SplFileInfoRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/StringRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/TableRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/Representation/ValueRepresentation.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ResourceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/SimpleXMLElementValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/SplFileInfoValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/StreamValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/StringValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/ThrowableValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/TraceFrameValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/TraceValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/UninitializedValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/UnknownValue.php
./codeigniter4/framework/system/ThirdParty/Kint/Value/VirtualValue.php
./codeigniter4/framework/system/util_bootstrap.php
./codeigniter4/framework/writable/debugbar/index.html
./ezyang/htmlpurifier/library/HTMLPurifier/ConfigSchema/schema/URI.AllowedSymbols.txt
./ezyang/htmlpurifier/library/HTMLPurifier/ConfigSchema/schema/URI.SafeIframeHosts.txt
./guzzlehttp/psr7/src/Rfc3986.php
./guzzlehttp/psr7/UPGRADING.md
./laminas/laminas-escaper/src/EscaperInterface.php
./symfony/polyfill-mbstring/bootstrap72.php
./symfony/polyfill-mbstring/Resources/unidata/eastAsianWidthRegexp.php
./symfony/polyfill-mbstring/Resources/unidata/titleCase.php
./symfony/polyfill-php80/bootstrap.php
./symfony/polyfill-php80/composer.json
./symfony/polyfill-php80/LICENSE
./symfony/polyfill-php80/Php80.php
./symfony/polyfill-php80/PhpToken.php
./symfony/polyfill-php80/README.md
./symfony/polyfill-php80/Resources/stubs/Attribute.php
./symfony/polyfill-php80/Resources/stubs/PhpToken.php
./symfony/polyfill-php80/Resources/stubs/Stringable.php
./symfony/polyfill-php80/Resources/stubs/UnhandledMatchError.php
./symfony/polyfill-php80/Resources/stubs/ValueError.php
./symfony/polyfill-php83/bootstrap72.php
./symfony/yaml/ParserState.php
```

## Runtime notes (rain-proven)

- admserv-php **8.3.x** for CI 4.7.5 tests; long-term target **8.4.27** (separate epic).
- AdmServ `php.ini`: `auto_prepend_file =` (empty). See `admserv-php-no-auto-prepend.md`.
- Session: fix validation_helper `$data`/`preg_match` clash; defensive `bx_session_sanitize_numeric_keys()`.
- GoAccess live WS: sitestats module cookie-gated `/goaccess-ws/` (not alpine).
