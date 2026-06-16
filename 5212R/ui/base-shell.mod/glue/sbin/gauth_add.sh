#!/bin/bash
#
# This script is used to create Google Authenticator configs and QR images for users
# Parameter: The username of the user who should get these files.
#

# Check if a username parameter was provided
if [[ $# -eq 0 ]]; then
    echo "Usage: $0 username"
    exit 1
fi

# Get the username from the first parameter
username=$1

# Check if the user exists:
USERCHECK=$(getent passwd $username | wc -l)
if [ $USERCHECK -eq "0" ];then
    echo "ERROR: That user does not exist."
    exit 1
fi

# Get the user's home directory
homedir=$(getent passwd $username | cut -d: -f6)

# Get UID and GID:
uid=$(id -u $username)
gid=$(id -g $username)

# Get Vsite (if any):
NAME_OF_GROUP=$(getent group ${gid} | cut -d: -f1)
if [ "$NAME_OF_GROUP" == "users" ];then
    FQDN=$(uname -n)
else
    FQDN=$(echo "Find Vsite name = ${NAME_OF_GROUP}" | /usr/sausalito/bin/cceclient |grep ^104|awk '{ print "GET " $3 }'| /usr/sausalito/bin/cceclient |grep '^102 DATA fqdn'|cut -d \" -f2)
fi

# Run google-authenticator for the specified user
/usr/bin/yes "y" | /usr/bin/google-authenticator -t -f -d -C -q -s "$homedir/.google_authenticator" &>/dev/null || :

# Change ownership of the .google_authenticator file to the correct user and group
if [ -f "$homedir/.google_authenticator" ];then
    chown $uid:$gid "$homedir/.google_authenticator"
else 
    echo ""
    echo "ERROR: No $homedir/.google_authenticator present!"
    exit 1
fi

# Get the URL of the authenticator image
SECRET_KEY=$(head -1 "$homedir/.google_authenticator")
/usr/bin/qrencode -t PNG -o "$homedir/.google_authenticator.png" "otpauth://totp/$username@$FQDN?secret=${SECRET_KEY}&issuer=BlueOnyx"
if [ -f "$homedir/.google_authenticator.png" ];then
    chown $uid:$gid "$homedir/.google_authenticator.png"
    chmod 0400 "$homedir/.google_authenticator.png"
fi

# Make sure that group 'google-authenticator' exists:
if ! getent group google-authenticator >/dev/null 2>&1; then
    groupadd google-authenticator
fi

# Check if the user is already a member of the group 'google-authenticator'. If not, add him:
authgroupname=google-authenticator
if id -nG "$username" | grep -qw "$authgroupname"; then
    echo "User $username is already a member of group $authgroupname"
else
    # Add the user to the group
    if [ -f /etc/group.lock ];then
        rm -f /etc/group.lock
    fi
    if usermod -aG "$authgroupname" "$username"; then
        echo "Added user $username to group $authgroupname"
    else
        echo "Failed to add user $username to group $authgroupname"
        exit 1
    fi
fi

# Special case user 'admin': Add the authenticator to the 'root' account as well, 
# BUT ONLY if SSHd isn't configured to use 'PermitRootLogin without-password':
RootWithoutPassword=$(cat /etc/ssh/sshd_config |grep '^PermitRootLogin without-password'|wc -l)
if [ "$username" = 'admin' ];then
    # 'PermitRootLogin without-password' is NOT set, add 2FA to 'root' account
    if [ "$RootWithoutPassword" -eq "0" ];then
        cp "$homedir/.google_authenticator" /root/.google_authenticator
        chown root:root /root/.google_authenticator
        # Now add user 'root' to $authgroupname as well:
        if ! id -nG "root" | grep -qw "$authgroupname"; then
            if usermod -aG "$authgroupname" root; then
                echo "Added user 'root' to group $authgroupname"
            else
                echo "Failed to add user 'root' to group $authgroupname"
                exit 1
            fi
        fi
    else 
        # We have 'PermitRootLogin without-password' AND user 'root' already has an auth file.
        # Remove it:
        echo "This server has 'PermitRootLogin without-password' set. Removing 2FA from 'root' and removing user root from 'google-authenticator' group."
        rm -f /root/.google_authenticator
        # And just to be sure we remove 'root' from the 'google-authenticator' group:
        if id -nG "root" | grep -qw "$authgroupname"; then
            gpasswd -d root google-authenticator
        fi
    fi
fi

exit 0

# 
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#     notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#     notice, this list of conditions and the following disclaimer in 
#     the documentation and/or other materials provided with the 
#     distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#     contributors may be used to endorse or promote products derived 
#     from this software without specific prior written permission.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
# POSSIBILITY OF SUCH DAMAGE.
# 
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
# 
