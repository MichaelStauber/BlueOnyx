<?php

class I18nExtension
{
    private $handle = 0;
    private $language = 'en_US';
    private $domain = 'palette';

    public function setLanguage($language = 'en_US')
    {
        $this->language = $language ?: 'en_US';

        if ($this->handle) {
            $this->handle = i18n_new($this->domain, $this->language);
        }
    }

    public function getLanguage()
    {
        return $this->language;
    }

    public function setDomain($domain)
    {
        $this->domain = $domain ?: 'palette';

        if ($this->handle) {
            $this->handle = i18n_new($this->domain, $this->language);
        }
    }

    public function getDomain()
    {
        return $this->domain;
    }

    public function i18n_new($domain = '', $langs = 'en_US')
    {
        $this->setLanguage($langs);
        $this->setDomain($domain);
        $this->handle = i18n_new($this->domain, $this->language);

        if (!$this->handle) {
            trigger_error('i18n_new did not return a handle', E_WARNING);
            return 0;
        }

        return $this->handle;
    }

    public function i18n_get($tag, $domain = '', $vars = [])
    {
        $useDomain = $domain ?: $this->domain;
        return i18n_get($this->handle, $tag, $useDomain, (array) $vars);
    }

    public function i18n_get_js($tag, $domain = '', $vars = [])
    {
        $useDomain = $domain ?: $this->domain;
        return i18n_get_js($this->handle, $tag, $useDomain, (array) $vars);
    }

    public function i18n_get_html($tag, $domain = '', $vars = [])
    {
        $useDomain = $domain ?: $this->domain;
        return i18n_get_html($this->handle, $tag, $useDomain, (array) $vars);
    }

    public function i18n_get_property($property, $domain, $language = '')
    {
        $useLanguage = $language ?: $this->language;
        return i18n_get_property($this->handle, $property, $domain, $useLanguage);
    }

    public function i18n_get_file($file)
    {
        return i18n_get_file($this->handle, $file);
    }

    public function i18n_availlocales($domain = '')
    {
        return i18n_availlocales($domain);
    }

    public function i18n_locales($domain = '')
    {
        $useDomain = $domain ?: $this->domain;
        return i18n_locales($this->handle, $useDomain);
    }

    public function i18n_strftime($format, $time)
    {
        return i18n_strftime($this->handle, $format, $time);
    }

    public function i18n_interpolate($magicStr, $vars = [])
    {
        return i18n_interpolate($this->handle, $magicStr, (array) $vars);
    }

    public function i18n_interpolate_js($magicStr, $vars = [])
    {
        return i18n_interpolate_js($this->handle, $magicStr, (array) $vars);
    }

    public function i18n_interpolate_html($magicStr, $vars = [])
    {
        return i18n_interpolate_html($this->handle, $magicStr, (array) $vars);
    }
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
?>