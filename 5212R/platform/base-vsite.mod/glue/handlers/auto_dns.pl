#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: auto_dns.pl
#
# handle auto dns configuration for a virtual site
#
# Note: We used to do CNAME records for aliases. Which is
#       a horrible sin. We preach NOT to use CNAME records
#       and then this bloody script went ahead and created
#       them! <waaaaaah!!!!>
#       So all aliases are now created as A records instead
#       and encountered CNAME records will be deleted.
#
#       The ONLY CNAME records we create are for Email-
#       Autoconfigure: autoconfig.* & autodiscover.*
#

use CCE;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE("Domain" => "base-vsite");
$cce->connectfd();

# variables needed
my ($ok, $badkeys, $vsite, $vsite_new, $vsite_old);

$vsite = $cce->event_object();
$vsite_new = $cce->event_new();
$vsite_old = $cce->event_old();

&debug_msg("Start of Auto-DNS validation.\n");

# find the system oid for DNS restarts
my ($sysoid) = $cce->find("System");

# find the DNS OID, and get settings.
my ( $ok, $dns_config ) = $cce->get($sysoid, "DNS");

my @marked_for_death = ();

# Handle site deletion
if ($vsite_old->{dns_auto} && $cce->event_is_destroy()) {
    # Find & destroy auto-created records

    # A
    @marked_for_death = $cce->find('DnsRecord',
                {
                    'hostname' => $vsite_old->{hostname},
                    'domainname' => $vsite_old->{domain},
                    'ipaddr' => $vsite_old->{ipaddr},
                });

    # Start NuOnce Auto DNS Addon #
    # Auto DNS Remove Entries
    my @auto_a_records = $cce->scalar_to_array($dns_config->{'auto_a'});

    foreach my $a_record(@auto_a_records) {
        if ( $a_record ne $vsite_old->{hostname} ) {
            push(@marked_for_death,
                $cce->find('DnsRecord', {
                    'hostname' => $a_record,
                    'domainname' => $vsite_old->{domain},
                    'ipaddr' => $vsite_old->{ipaddr},
                }));
        }
    }

    # Auto No Hostname
    push(@marked_for_death,
        $cce->find('DnsRecord', {
            'hostname' => "",
            'domainname' => $vsite_old->{domain},
            'ipaddr' => $vsite_old->{ipaddr},
        }));

    # Auto DNS MX
    push(@marked_for_death,
        $cce->find('DnsRecord',{
            'type' => 'MX',
            'hostname' => '',
            'domainname' => $vsite_old->{domain},
            'mail_server_name' => $dns_config->{'auto_mx'} . "." . $vsite_old->{domain},
    }));
    # End NuOnce Auto DNS Addon #


    # CNAME
    push(@marked_for_death,
         $cce->find('DnsRecord',
            {
                'alias_hostname' => $vsite_old->{hostname},
                'alias_domainname' => $vsite_old->{domain}
            }));

    # MX
    push(@marked_for_death,
         $cce->find('DnsRecord',
            {
                'mail_server_name' => $vsite_old->{hostname} .
                    '.' . $vsite_old->{domain}
            }));

    # web & email host aliases
    my @alias_hosts = $cce->scalar_to_array($vsite_old->{mailAliases});
    push(@alias_hosts, $cce->scalar_to_array($vsite_old->{webAliases}));
    foreach my $host (@alias_hosts) {
        $host =~ s/\.$vsite_old->{domain}$//; # strip domain
        &debug_msg("Searching for records hostname: $host\n");
        push(@marked_for_death,
             $cce->find('DnsRecord',
                {
                    'hostname' => $host,
                    'domainname' => $vsite_old->{domain},
                    'ipaddr' => $vsite_old->{ipaddr},
                }));
    }

    foreach my $rip (@marked_for_death) {
        &debug_msg("Deleting oid: $rip...\n");
        next unless ($rip);
        my($ok) = $cce->destroy($rip);
    }

    # Remove email autoconfig/autodiscovery records if present:
    if ($vsite_old->{email_autoconfig}) {
        my $zone = autodiscovery_zone_for_vsite($vsite_old);
        remove_email_autodiscovery_records(
            cce  => $cce,
            zone => $zone,
        );
    }

    &debug_msg("Commiting changes to bind\n");
    my($ok) = $cce->set($sysoid, "DNS", { 'commit' => time() });
    $cce->bye('SUCCESS');
    exit 0;
}

# auto-config DNS
if ($vsite_new->{dns_auto}) {
    #
    # check if there is already a matching dns record for this site
    # if there is we don't add another one
    #
    my ($dns_record) = $cce->find("DnsRecord", 
                      { 
                    'type' => 'A',
                    'hostname' => $vsite->{hostname},
                    'domainname' => $vsite->{domain},
                    'ipaddr' => $vsite->{ipaddr},
                      });
    
    if (not $dns_record) {
        ($ok) = $cce->create("DnsRecord", 
                     {
                    'type' => 'A',
                    'hostname' => $vsite->{hostname},
                    'domainname' => $vsite->{domain},
                    'ipaddr' => $vsite->{ipaddr},
                     });
        if (not $ok) {
            $cce->bye('FAIL', 'cantCreateAtypeRecord',
                  { 'fqdn' => $vsite->{fqdn} });
            exit(1);
        }
    }

    # Start NuOnce Auto DNS Addon #
    my @auto_a_records = $cce->scalar_to_array($dns_config->{'auto_a'});

    foreach my $a_record(@auto_a_records) {

        # If these show up in auto_a, NEVER create A for them.
        next if is_reserved_autodiscovery_host($a_record);

        my ($dns_record) = $cce->find("DnsRecord", {
            'type' => 'A',
            'hostname' => $a_record,
            'domainname' => $vsite->{domain},
            'ipaddr' => $vsite->{ipaddr},
            });
        if (not $dns_record) {
            ($ok) = $cce->create("DnsRecord", {
                'type' => 'A',
                'hostname' => $a_record,
                'domainname' => $vsite->{domain},
                'ipaddr' => $vsite->{ipaddr},
                });
        }
    }

    # Auto No Hostname
    my ($dns_record) = $cce->find("DnsRecord", {
        'type' => 'A',
        'hostname' => "",
        'domainname' => $vsite->{domain},
        'ipaddr' => $vsite->{ipaddr},
        });
    if (not $dns_record) {
        ($ok) = $cce->create("DnsRecord", {
            'type' => 'A',
            'hostname' => "",
            'domainname' => $vsite->{domain},
            'ipaddr' => $vsite->{ipaddr},
            });
    }   

    my ($dns_record) = $cce->find("DnsRecord", {
        'type' => 'MX',
        'hostname' => '',
        'domainname' => $vsite->{domain},
        'mail_server_name' => $dns_config->{'auto_mx'} . "." . $vsite->{domain},
        });
    if (not $dns_record) {
        ($ok) = $cce->create("DnsRecord", {
            'type' => 'MX',
            'hostname' => '',
            'domainname' => $vsite->{domain},
            'mail_server_name' => $dns_config->{'auto_mx'} . "." . $vsite->{domain},
            'mail_server_priority' => 'very_high',
            });
    }
    # End NuOnce Auto DNS Addon #

    # Email autodiscovery / autoconfig records:
    if ($vsite->{email_autoconfig}) {
        my $mailhost = $dns_config->{'auto_mx'} || 'mail';

        my $zone = autodiscovery_zone_for_vsite($vsite);

        ensure_email_autodiscovery_records(
            cce         => $cce,
            vsite       => $vsite,
            base_domain => $vsite->{domain},
            port        => 443,
        );
    }
    else {
        # Optional but recommended: if email_autoconfig is off, remove any auto-created ones
        my $zone = autodiscovery_zone_for_vsite($vsite);
        remove_email_autodiscovery_records(
            cce  => $cce,
            zone => $zone,
        );
    }
}
elsif ($vsite->{dns_auto} && ($vsite_new->{ipaddr} || $vsite_new->{hostname} || $vsite_new->{domain})) {
    # migrate fqdn
    my @dns_records = $cce->find("DnsRecord", 
                     { 
                    'hostname' => $vsite_old->{hostname},
                    'domainname' => $vsite_old->{domain},
                     });

    &update_records($vsite_old->{fqdn},
                    {
                'hostname' => $vsite->{hostname},
                'domainname' => $vsite->{domain},
            },
            @dns_records);

    # migrate ip address
    @dns_records = $cce->find("DnsRecord", 
                  { 
                    'domainname' => $vsite_old->{domain},
                    'ipaddr' => $vsite_old->{ipaddr}, 
                  });

    &update_records($vsite_old->{fqdn},
            {
                'domainname' => $vsite->{domain},
                'ipaddr' => $vsite->{ipaddr} 
            },
            @dns_records);

    # migrate web (CNAME) aliases
    @dns_records = $cce->find('DnsRecord',
            {
                'alias_hostname' => $vsite_old->{hostname},
                'alias_domainname' => $vsite_old->{domain}
            });

    &update_records($vsite_old->{fqdn},
                    {    
                'alias_hostname' => $vsite->{hostname},
                'alias_domainname' => $vsite->{domain}
            },
            @dns_records);
    
    # migrate site MX (mail aliases)
    @dns_records = $cce->find('DnsRecord',
            {
                'mail_server_name' => $vsite_old->{hostname} .
                    '.' . $vsite_old->{domain}
            });

    &update_records($vsite_old->{fqdn},
                    {
                'mail_server_name' => $vsite->{hostname} .
                    '.' . $vsite->{domain},
            },
            @dns_records);

    # If domain changed, move the email autoconfig/autodiscovery records accordingly:
    if ($vsite->{email_autoconfig}) {

        # If hostname or domain changed, remove from the OLD autodiscovery zone first:
        if ($vsite_new->{domain} || $vsite_new->{hostname}) {
            my $old_zone = autodiscovery_zone_for_vsite($vsite_old);
            remove_email_autodiscovery_records(
                cce  => $cce,
                zone => $old_zone,
            );
        }

        my $mailhost = $dns_config->{'auto_mx'} || 'mail';
        my $zone = autodiscovery_zone_for_vsite($vsite);

        ensure_email_autodiscovery_records(
            cce         => $cce,
            vsite       => $vsite,
            base_domain => $vsite->{domain},
            port        => 443,
        );
    }
}

# Handle toggling email_autoconfig without toggling dns_auto / hostname / domain / ip:
if (!$vsite_new->{dns_auto} && $vsite->{dns_auto} && defined($vsite_new->{email_autoconfig})) {

    # If hostname or domain changed in same transaction, clean old zone first
    if ($vsite_new->{domain} || $vsite_new->{hostname}) {
        my $old_zone = autodiscovery_zone_for_vsite($vsite_old);
        remove_email_autodiscovery_records(
            cce  => $cce,
            zone => $old_zone,
        );
    }

    my $zone = autodiscovery_zone_for_vsite($vsite);

    if ($vsite->{email_autoconfig}) {
        ensure_email_autodiscovery_records(
            cce         => $cce,
            vsite       => $vsite,
            base_domain => $vsite->{domain},
            port        => 443,
        );
    }
    else {
        remove_email_autodiscovery_records(
            cce  => $cce,
            zone => $zone,
        );
    }
}

my (@used_aliases, @add_aliases, @remove_aliases, %new_aliases, %old_aliases);

# add new A and MX records for email site fqdn aliases
if ($vsite->{dns_auto} &&
    ($vsite->{mailAliases} || defined($vsite_new->{mailAliases}))) {
    #
    # new aliases are taken from the composite object since
    # it will have the new values, plus $vsite_new won't be defined
    # if this is being run to turn on auto dns
    #
    %new_aliases = map { $_ => 1 } 
    $cce->scalar_to_array($vsite->{mailAliases});
    
    #
    # old_aliases should be null if auto dns is just being turned on, and
    # it should be whatever the old aliases actually are if auto dns
    # was already on
    #
    if ($vsite_new->{dns_auto}) {
        %old_aliases = ();
    }
    else {
        %old_aliases = map { $_ => 1 } 
        $cce->scalar_to_array($vsite_old->{mailAliases});
    }

    my @mx_priorities = ('very_high', 'high', 'low', 'very_low');
    my @mx_count = $cce->find('DnsRecord', 
                  {
                    'type' => 'MX',
                    'mail_server_name' => $vsite->{fqdn}
                  });
    my $mx_index = $#mx_count + 1;

    # figure out which aliases are new
    for my $alias (keys %new_aliases) {
        if (not exists($old_aliases{$alias})) {
            #
            # we only do auto dns for an alias if it is in the same
            # domain as the vsite
            #

            # Start sane per alias:
            my $alias_host = undef;

            if ($alias =~ /^(.*)\.\Q$vsite->{domain}\E$/) {
                $alias_host = $1;
            }
            elsif ($alias eq $vsite->{domain}) {
                $alias_host = "";
            }
            else {
                next; # not in this domain
            }

            # If these are present in mailAliases, do NOT create A/MX for them
            if (is_reserved_autodiscovery_host($alias_host)) {
                &debug_msg("Skipping reserved autodiscovery mailAlias \"$alias_host\" for A/MX creation.\n");
                next;
            }

            &debug_msg("First pass of processing webAlias\n");

            #### First pass for A records
        
            # check if the specified alias already exists
            my ($dns_record) = $cce->find("DnsRecord", 
                {
                    'type' => 'A',
                    'hostname' => $alias_host, 
                    'domainname' => $vsite->{domain},
                    'ipaddr' => $vsite->{ipaddr},
                });

            # don't add if it already exists
            unless ($dns_record) {
                #
                # make sure someone else isn't using
                # the alias
                #
                ($dns_record) = $cce->find("DnsRecord",
                    {
                    'type' => 'A',
                    'hostname' => $alias_host,
                    'domainname' => $vsite->{domain},
                    'ipaddr' => $vsite->{ipaddr}
                    });

                if ($dns_record) {
                    push @used_aliases,
                         ($alias_host . 
                        "." . $vsite->{domain});
                }
                else {
                    #
                    # The alias is all ours, delete
                    # A record first if necessary
                    my ($rip) = $cce->find("DnsRecord",
                        {
                        'type' => 'A',
                        'hostname' => $alias_host,
                        'domainname' => $vsite->{domain},
                        'ipaddr' => $vsite->{ipaddr}
                        }
                    );
                    if ($rip =~ /^\d+$/) {
                        $cce->destroy($rip);
                    }

                    ($ok) = $cce->create("DnsRecord",
                        {
                        'type' => 'A',
                        'hostname' => $alias_host,
                        'domainname' => $vsite->{domain},
                        'ipaddr' => $vsite->{ipaddr},
                        });

                    if (not $ok) {
                        $cce->bye('FAIL', 'cantCreateAtypeRecordForMail',
                                {
                                    'fqdn' => $vsite->{fqdn},
                                    'alias' => $alias
                                });
                        exit(1);
                    }
                }
            }

            #### Second pass for MX records
        
            # check if the specified alias already exists
            ($dns_record) = $cce->find("DnsRecord", 
                {
                'type' => 'MX',
                'hostname' => $alias_host, 
                'domainname' => $vsite->{domain},
                'mail_server_name' => $vsite->{fqdn},
                }
            );

            # don't add if it already exists
            if ($dns_record) {
                next;
            }

            # make sure someone else isn't using the alias
            ($dns_record) = $cce->find("DnsRecord",
                {
                'type' => 'MX',
                'hostname' => $alias_host,
                'domainname' => $vsite->{domain}
                });

            if ($dns_record) {
                push @used_aliases, ($alias_host . 
                    "." . $vsite->{domain});
                next;
            }

            # The alias is all ours
            $mx_index = 3 if ($mx_index > 3);
            &debug_msg("Creating MX record for $alias_host, priority index $mx_index\n");
            ($ok) = $cce->create("DnsRecord",
                {
                'type' => 'MX',
                'hostname' => $alias_host,
                'domainname' => $vsite->{domain},
                'mail_server_name' => $vsite->{fqdn},
                'mail_server_priority' => 
                    $mx_priorities[$mx_index],
                });
            $mx_index++;

            if (not $ok) {
                $cce->bye('FAIL', 'cantCreateMXRecordForMailAlias',
                        {
                            'fqdn' => $vsite->{fqdn},
                            'alias' => $alias
                        });
                exit(1);
        
            }
        }
    }
    
    # figure out which aliases should be destroyed
    for my $alias (keys %old_aliases) {
        if (not exists($new_aliases{$alias})) {
            my ($alias_host, $alias_domain);
            
            #
            # the alias still has the old domainname if the 
            # site's domain name changed
            #
            if ($vsite_new->{domain}) {
                $alias =~ /^(.*)\.$vsite_old->{domain}$/;
                $alias_host = $1;
                $alias_domain = $vsite_old->{domain};
            }
            else {
                $alias =~ /^(.*)\.$vsite->{domain}$/;
                $alias_host = $1;
                $alias_domain = $vsite->{domain};
            }

            my @dns_records = $cce->find("DnsRecord",
                {
                'type' => 'A',
                'hostname' => $alias_host,
                'domainname' => $alias_domain,
                'ipaddr' => $vsite->{ipaddr},
                }
            );
            push(@dns_records, $cce->find("DnsRecord",
                {
                'type' => 'MX',
                'hostname' => $alias_host,
                'domainname' => $alias_domain,
                'mail_server_name' => $vsite->{fqdn},
                }
            ));


            # delete all the records found
            for my $rec (@dns_records) {
                $cce->destroy($rec);
            }
        }
    }
}

# add new CNAME entries for web fqdn site aliases
if ($vsite->{dns_auto} &&
    ($vsite->{webAliases} || defined($vsite_new->{webAliases}))) {
    #
    # new aliases are taken from the composite object since
    # it will have the new values, plus $vsite_new won't be defined
    # if this is being run to turn on auto dns
    #
    %new_aliases = map { $_ => 1 } 
    $cce->scalar_to_array($vsite->{webAliases});

    @new_webAliases = $cce->scalar_to_array($vsite->{webAliases});

    #
    # old_aliases should be null if auto dns is just being turned on, and
    # it should be whatever the old aliases actually are if auto dns 
    # was already on
    #
    if ($vsite_new->{dns_auto}) {
        %old_aliases = ();
    }
    else {
        %old_aliases = map { $_ => 1 } 
        $cce->scalar_to_array($vsite_old->{webAliases}); 
    }

    # figure out which aliases are new
    foreach $alias (@new_webAliases) {

        # Start sane:
        my $alias_host = undef;

        &debug_msg("3 Processing webAlias: \"$alias\"\n");

        if ($alias =~ /^(.*)\.\Q$vsite->{domain}\E$/) {
            $alias_host = $1;
            &debug_msg("3a webAlias: \"$alias\" with hostname \"$alias_host\"\n");
        }
        elsif ($alias eq $vsite->{domain}) {
            $alias_host = "";
            &debug_msg("3b webAlias: \"$alias\" with hostname \"$alias_host\"\n");
        }
        else {
            next; # not in this domain
        }

        &debug_msg("4 Processing webAlias: \"$alias\" with hostname \"$alias_host\"\n");

        # check if an authoritative A record exists
        my ($dns_a_record) = $cce->find("DnsRecord", 
            {
            'type' => 'A',
            'hostname' => $alias_host, 
            'domainname' => $vsite->{domain},
            }
        );

        # If these are present in webAliases, do NOT create A for them
        if (is_reserved_autodiscovery_host($alias_host)) {
            &debug_msg("Skipping reserved autodiscovery alias \"$alias_host\" for A-record creation.\n");
            next;
        }

        # don't add if it already exists
        if ($dns_a_record) {
            &debug_msg("Skipping web alias record \"$alias_host\", already exists as OID $dns_a_record\n");
            next;
        } 

        # make sure someone else isn't using the alias
        ($dns_record) = $cce->find("DnsRecord",
            {
            'type' => 'A',
            'hostname' => $alias_host,
            'domainname' => $vsite->{domain}
            }
        );

        if ($dns_record) {
            push @used_aliases, ($alias_host . "." . $vsite->{domain});
            &debug_msg("Web alias record $alias_host in alternate use\n");
            next;
        }

        # Check for identical CNAME records and silently delete them:
        push(@marked_for_death,
             $cce->find('DnsRecord',
                {
                    'type' => "CNAME",
                    'hostname' => $alias_host,
                    'domainname' => $vsite->{domain}
                }));
        foreach my $rip (@marked_for_death) {
            &debug_msg("Deleting CNAME oid: $rip...\n");
            next unless ($rip);
            my($ok) = $cce->destroy($rip);
        }

        # The alias is all ours
        &debug_msg("Creating web alias record $alias_host\n");
        ($ok) = $cce->create("DnsRecord",
            {
            'type' => 'A',
            'hostname' => $alias_host,
            'domainname' => $vsite->{domain},
            'ipaddr' => $vsite->{ipaddr}
            }
        );
        if (not $ok) {
            &debug_msg("Create web alias record $alias_host FAILED, bailing\n");
            $cce->bye('FAIL', 'cantCreateWebAlias',
                           {
                               'fqdn' => $vsite->{fqdn},
                               'alias' => ($alias_host . $vsite->{domain})
                           });
            exit(1);
        }
    }
    
    # figure out which aliases should be destroyed
    for my $alias (keys %old_aliases) {
        if (not exists($new_aliases{$alias})) {
            my ($alias_host, $alias_domain);

            # the alias still has the old domainname if the 
            # site's domain name changed
            if ($vsite_new->{domain}) {
                $alias =~ /^(.*)\.$vsite_old->{domain}$/;
                $alias_host = $1;
                $alias_domain = $vsite_old->{domain};
            }
            else {
                $alias =~ /^(.*)\.$vsite->{domain}$/;
                $alias_host = $1;
                $alias_domain = $vsite->{domain};
            }

            my @dns_records = $cce->find("DnsRecord",
                {
                'type' => 'A',
                'hostname' => $alias_host,
                'domainname' => $alias_domain,
                'ipaddr' => $vsite->{ipaddr}
                });

            # delete all the records found
            for my $rec (@dns_records) {
                $DEBUG && warn "Deleting unused web alias record $rec\n";
                $cce->destroy($rec);
            }
        }
    }
}

if ($vsite->{dns_auto}) {
    &debug_msg("Commiting changes to bind\n");
    ($ok) = $cce->set($sysoid, "DNS", { 'commit' => time() });

    if (not $ok) {
        $cce->bye('FAIL', '[[base-vsite.cantRestartDns]]');
        exit(1);
    }
}

if (scalar(@used_aliases)) {
    &debug_msg("Warning about 'usedMailAliases'.\n");
    # We no longer warn about this, as the the Chorizo GUI treats it as fatal error:
    #$cce->warn("[[base-vsite.usedMailAliases,aliases='" . join(', ', @used_aliases) . "']]");
}

$cce->bye('SUCCESS');
exit(0);

# Fin
######

sub update_records
# 
{
    my $fqdn = shift;
    my $delta = shift;
    my @dns_records = @_;

    if($DEBUG) {
        &debug_msg("Migrating record oids:\n");
        &debug_msg(join(', ', @dns_records)."\n");
        foreach my $key (keys %{$delta}) {
            &debug_msg("Key: $key, val: " . $delta->{$key} . "\n"); 
        }
    }

    for my $rec (@dns_records) {
        ($ok) = $cce->set($rec, '', $delta);
        if (not $ok) {
            $cce->bye('FAIL', 'cantMigrateDnsRecords',
                  { 'fqdn' => $fqdn });
            exit(1); 
        }
    }
    return 1;
}

# ---- Email-Autodiscovery helpers ----

sub is_reserved_autodiscovery_host {
    my ($h) = @_;
    return 0 unless defined($h);

    $h =~ s/^\s+|\s+$//g;
    $h =~ s/\.$//;  # trailing dot

    # Strip the base domain if it was passed as FQDN
    $h =~ s/\.\Q$vsite->{fqdn}\E$//i  if (defined($vsite->{fqdn}));
    $h =~ s/\.\Q$vsite->{domain}\E$//i if (defined($vsite->{domain}));

    # Block exact AND dotted variants (autoconfig, autoconfig.banana, etc.)
    return 1 if ($h =~ /^autoconfig(\.|$)/i);
    return 1 if ($h =~ /^autodiscover(\.|$)/i);
    return 1 if ($h =~ /^_autodiscover\._tcp(\.|$)/i);

    return 0;
}

sub ensure_destroy_dns_oids {
    my ($cce, @oids) = @_;
    foreach my $oid (@oids) {
        next unless ($oid && $oid =~ /^\d+$/);
        $cce->destroy($oid);
    }
}

# Creates:
#   autoconfig.<domain>   CNAME <mailhost>.<domain>
#   autodiscover.<domain> CNAME <mailhost>.<domain>
#   _autodiscover._tcp.<domain> SRV 0 0 443 autodiscover.<domain>
sub ensure_email_autodiscovery_records {
    my (%args)      = @_;
    my $cce         = $args{cce};
    my $vsite       = $args{vsite};
    my $base_domain = $args{base_domain};  # smd.net / kinofreak.com
    my $port        = $args{port} || 443;

    return unless ($cce && $vsite && $base_domain);

    my $suffix        = autodiscovery_host_suffix_for_vsite($vsite);  # '' or '.banana'
    my $record_domain = $base_domain;

    my $target_fqdn = pick_autodiscovery_cname_target_fqdn($cce, $vsite);
    my ($t_host, $t_domain) = split_target_for_cname($target_fqdn, $base_domain);

    my $h_autoconfig   = "autoconfig$suffix";
    my $h_autodiscover = "autodiscover$suffix";
    my $h_srv          = "_autodiscover._tcp$suffix";

    # SRV MUST point to mail.<domain> or vsite fqdn (your established rule):
    my $srv_target = $target_fqdn;

    &debug_msg("Email-Autodiscovery: domain=$record_domain suffix=$suffix cname_target=$target_fqdn srv_target=$srv_target\n");

    # Nuke wrong A records
    ensure_destroy_dns_oids($cce,
        $cce->find('DnsRecord', { type=>'A', hostname=>$h_autoconfig,   domainname=>$record_domain }),
        $cce->find('DnsRecord', { type=>'A', hostname=>$h_autodiscover, domainname=>$record_domain }),
        $cce->find('DnsRecord', { type=>'A', hostname=>$h_srv,          domainname=>$record_domain }),
    );

    # --- autoconfig CNAME
    my ($cname1) = $cce->find('DnsRecord', {
        type       => 'CNAME',
        hostname   => $h_autoconfig,
        domainname => $record_domain,
    });

    if ($cname1) {
        $cce->set($cname1, '', { alias_hostname => $t_host, alias_domainname => $t_domain });
    }
    else {
        my $ok = $cce->create('DnsRecord', {
            type             => 'CNAME',
            hostname         => $h_autoconfig,
            domainname       => $record_domain,
            alias_hostname   => $t_host,
            alias_domainname => $t_domain,
        });
        &debug_msg("Create CNAME $h_autoconfig.$record_domain => $target_fqdn failed\n") if (!$ok);
    }

    # --- autodiscover CNAME
    my ($cname2) = $cce->find('DnsRecord', {
        type       => 'CNAME',
        hostname   => $h_autodiscover,
        domainname => $record_domain,
    });

    if ($cname2) {
        $cce->set($cname2, '', { alias_hostname => $t_host, alias_domainname => $t_domain });
    }
    else {
        my $ok = $cce->create('DnsRecord', {
            type             => 'CNAME',
            hostname         => $h_autodiscover,
            domainname       => $record_domain,
            alias_hostname   => $t_host,
            alias_domainname => $t_domain,
        });
        &debug_msg("Create CNAME $h_autodiscover.$record_domain => $target_fqdn failed\n") if (!$ok);
    }

    # --- SRV
    my ($srv) = $cce->find('DnsRecord', {
        type       => 'SRV',
        hostname   => $h_srv,
        domainname => $record_domain,
    });

    if ($srv) {
        $cce->set($srv, '', {
            srv_target   => $srv_target,
            srv_port     => $port,
            srv_priority => 0,
            srv_weight   => 0,
        });
    }
    else {
        my $ok = $cce->create('DnsRecord', {
            type         => 'SRV',
            hostname     => $h_srv,
            domainname   => $record_domain,
            srv_target   => $srv_target,
            srv_port     => $port,
            srv_priority => 0,
            srv_weight   => 0,
        });
        &debug_msg("Create SRV $h_srv.$record_domain => $srv_target failed\n") if (!$ok);
    }

    return 1;
}

# Removes the three records (and also any wrong A records if they exist)
sub remove_email_autodiscovery_records {
    my (%args) = @_;
    my $cce  = $args{cce};
    my $zone = $args{zone};
    return unless ($cce && $zone);

    ensure_destroy_dns_oids($cce,
        $cce->find('DnsRecord', { type=>'A',     hostname=>'autoconfig',         domainname=>$zone }),
        $cce->find('DnsRecord', { type=>'A',     hostname=>'autodiscover',       domainname=>$zone }),
        $cce->find('DnsRecord', { type=>'A',     hostname=>'_autodiscover._tcp', domainname=>$zone }),

        $cce->find('DnsRecord', { type=>'CNAME', hostname=>'autoconfig',         domainname=>$zone }),
        $cce->find('DnsRecord', { type=>'CNAME', hostname=>'autodiscover',       domainname=>$zone }),

        $cce->find('DnsRecord', { type=>'SRV',   hostname=>'_autodiscover._tcp', domainname=>$zone }),
    );
}

sub pick_autodiscovery_cname_target_fqdn {
    my ($cce, $vsite) = @_;

    my @mail_aliases = $cce->scalar_to_array($vsite->{mailAliases} || '');

    # If any mailAlias starts with "mail." use that FQDN as target:
    foreach my $a (@mail_aliases) {
        next unless defined $a;
        $a =~ s/^\s+|\s+$//g;
        next unless $a;
        return $a if ($a =~ /^mail\./i);
    }

    # Otherwise fall back to the vsite FQDN:
    return $vsite->{fqdn};
}

sub split_target_for_cname {
    my ($target_fqdn, $base_domain) = @_;
    return ('', '') unless $target_fqdn;

    $target_fqdn =~ s/\.$//;            # strip trailing dot if present

    # Preferred: if it ends with the known base domain, split there:
    if ($base_domain && $target_fqdn =~ /\.\Q$base_domain\E$/i) {
        my $host = $target_fqdn;
        $host =~ s/\.\Q$base_domain\E$//i;  # remove ".base_domain"
        return ($host, $base_domain);
    }

    # Fallback: split on first dot into host + remainder:
    my ($host, $rest) = split(/\./, $target_fqdn, 2);
    return ($host, $rest || '');
}

sub autodiscovery_zone_for_vsite {
    my ($vsite) = @_;
    return $vsite->{domain} if (!$vsite || !defined $vsite->{hostname});

    # If the Vsite isn't www or mail: use full FQDN as zone
    if (($vsite->{hostname} ne 'www') && ($vsite->{hostname} ne 'mail')) {
        return $vsite->{fqdn};
    }
    return $vsite->{domain};
}

sub autodiscovery_host_suffix_for_vsite {
    my ($vsite) = @_;
    return '' unless ($vsite && $vsite->{fqdn} && $vsite->{domain});

    my $suffix = '';
    my $prefix = $vsite->{fqdn};
    $prefix =~ s/\.\Q$vsite->{domain}\E$//i;   # banana (or foo.bar)

    # For www/mail vsites, treat as base domain:
    return '' if (!$prefix || $prefix eq 'www' || $prefix eq 'mail');

    return ".$prefix";
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2008 Brian N. Smith / NuOnce Networks, Inc.
# Copyright (c) 2003 Sun Microsystems, Inc. 
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#    notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#    notice, this list of conditions and the following disclaimer in 
#    the documentation and/or other materials provided with the 
#    distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#    contributors may be used to endorse or promote products derived 
#    from this software without specific prior written permission.
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
