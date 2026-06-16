#!/usr/bin/perl -I/usr/sausalito/perl
# Copyright (c) Turbolinux, inc.
# Modified by Michael Stauber <mstauber@solarspeed.net> 

use strict;
use CCE;
use Getopt::Long;
use I18n;
use SendEmail;
use Sys::Hostname;
use POSIX;
use MIME::Lite;
use Encode;

my $host = hostname();
my $now = localtime time;

my @statecodes = ("N", "G", "Y", "R"),
my %params;
&GetOptions(
    "conf|c=s"  => \$params{'conf'},
    "delay|d"   => \$params{'delay'} # Add the delay parameter
);

my $conf = $params{'conf'};

# Introduce a random delay if the delay parameter is set
if ($params{'delay'}) {
    my $random_delay = int(rand(180)); # Generate a random number between 0 and 179
    print "Introducing a random delay of $random_delay seconds before executing...\n";
    sleep($random_delay); # Delay execution
}

open(CONF, "< $conf");

# Temporary file with AM attachment:
my $cpu_msg = '';
my $cpu_msg_file = '/tmp/' . join('', map { chr(int(rand(26)) + 65) } 1..8) . '_swatch.txt';

my @email_list;
my $body = "";

my $lang = "en";
my $enabled = "true";

while (<CONF>) {
    chomp;
    my($key, $val) = split /\s*=\s*/, $_, 2;
    if ($key eq "email_list") {
        @email_list = split /\s*,\s*/, $val;
    }
    elsif ($key eq "lang") {
        $lang = $val;
    }
    elsif ($key eq "enabled") {
        $enabled = $val;
    }
}

if ($enabled eq "false") {
    exit 0;
}

close(CONF);

my $object;
my $i18n = new I18n();

my $DEBUG_ME = 0;

my $cce = new CCE;
$cce->connectuds();

my @sysoid = $cce->find ('System');
my ($ok, $sysobj) = $cce->get($sysoid[0]);
my $system_lang = $sysobj->{productLanguage};
my $platform = $sysobj->{productBuild};

# We can't email in Japanese. Neither MIME:Lite alone could do that, nor does SendEmail or Email::Simple
# allow us to do that without a hell of a lot of complications. So for now we hard code Japanese AM emails
# to send in 'en_US' for emailing purpose from within this script:

if (($system_lang eq "ja") || ($system_lang eq "ja_JP")) {
  $i18n->setLocale("en_US");

}
else {
  $i18n->setLocale($system_lang);
}

my $body_head = $i18n->get('[[swatch.emailBody]]') . "\n\n";

my @oid = $cce->find ('ActiveMonitor');
my ($ok, @names, @info) = $cce->names ('ActiveMonitor');
#print $oid[0], "\n";
#print @names, "\n";
my @states = ("N", "G", "Y", "R");

my $globalState = "N";

my %stats = {};

my @tcp_ports;
open TCP, "/proc/net/tcp";
while (<TCP>) {
    my @fields = split / +/, $_;
    my ($laddr, $lport) = split /:/, $fields[2];
    my ($raddr, $rport) = split /:/, $fields[3];
    if ($raddr eq "00000000") {
        push @tcp_ports, $lport;
    }
}
close TCP;

my @udp_ports;
open UDP, "/proc/net/udp";
while (<UDP>) {
    my @fields = split / +/, $_;
    my ($laddr, $lport) = split /:/, $fields[2];
    my ($raddr, $rport) = split /:/, $fields[3];
    if ($raddr eq "00000000") {
        push @udp_ports, $lport;
    }
}
close UDP;

while ( defined (my $name = <@names>) ) {
    (my $ok, $object, my $old, my $new) = $cce->get ($oid[0], $name);
    if ( $object->{enabled} && $object->{monitor} ) {
        my $msg;
        my $agg_msg;
        my $state = 0;
        if ($object->{type} eq "aggregate") {
            print "$name aggregates $object->{typeData}\n" if ($DEBUG_ME);

            my $oldState = $object->{currentState};
            if ($DEBUG_ME) {
                $oldState = "G";                
            }

            my @aggregates = split / +/, $object->{typeData};
            foreach my $aggregate (@aggregates) {
                my ($ok, $obj, $old, $new) = $cce->get ($oid[0], $aggregate);
                if ($obj->{enabled} && $obj->{monitor}) {
                    ($stats{"$aggregate"}, my $ret, $agg_msg) = do_monitor($obj);
                    if (defined($agg_msg) && ($agg_msg ne "")) {
                        $msg .= "\n - $agg_msg";
                    }
                }
                if ($state < $stats{"$aggregate"}) {
                    $state = $stats{"$aggregate"};
                }
                $stats{"$name"} = $state;
            }

            if ($state > 0) {
                my @msgs = ("", "", "", "");
                $msgs[1] = $object->{greenMsg} if (defined ($object->{greenMsg}));
                $msgs[2] = $object->{yellowMsg} if (defined ($object->{yellowMsg}));
                $msgs[3] = $object->{redMsg} if (defined ($object->{redMsg}));

                $cce->set ($oid[0], $name, {
                         currentState => $states[$state],
                         lastChange=>time(),
                         currentMessage => $msgs[$state],
                         });

                if (($oldState ne $statecodes[$state]) && !($oldState eq "N" && $statecodes[$state] eq "G")) {
                    print "Got MSG start \n" if ($DEBUG_ME);
                    $msg = $i18n->get($msgs[$state]) . $msg;

                }
                else {
                    $msg = "";
                }
                print "[$name] state : $statecodes[$state]\n" if ($DEBUG_ME);
            }
        }
        elsif (!$object->{aggMember}) {
            ($stats{"$name"}, my $ret, $msg) = do_monitor($object);
        }
        if ($msg) {
            $body .= "* " . $msg . "\n\n";
        }
    }
}

# Update $globalState:
$cce->set ($oid[0], "", { globalState => $globalState });

if (-f $cpu_msg_file) {
    open(my $fh, '<', $cpu_msg_file) or die "Could not open file '$cpu_msg_file' $!";
    while (my $line = <$fh>) {
      $cpu_msg .= $line;
    }
    close($fh);
}

if ($cpu_msg eq "") {

    if ($body) {
        $body = $body_head . $body;
        my $subject = $host . ": " . encode('UTF-8', $i18n->get("[[swatch.emailSubject]]"));
        my $email_body = encode('UTF-8', $body);
        my $to;

        foreach $to (@email_list) {
            # Build the message using MIME::Lite:
            my $send_msg = MIME::Lite->new(
                    From     => "root",
                    To       => $to,
                    Subject  => $subject,
                    Data     => $email_body,
                    Charset => 'utf-8',
                    Encoding => 'quoted-printable'
                );

            # Set content type:
            $send_msg->attr("content-type"         => 'text/plain');
            $send_msg->attr("content-type.charset" => "utf-8");

            # Out with the email:
            $send_msg->send;
            print "Sending mail without attachment\n" if ($DEBUG_ME);
        }
    }
}
else {
    if ($body) {
        $body = $body_head . $body;
        my $subject = $host . ": " . encode('UTF-8', $i18n->get("[[swatch.emailSubject]]"));
        my $email_body = encode('UTF-8', $body);
        my $to;

        foreach $to (@email_list) {
            # Build the message using MIME::Lite:
            my $email = MIME::Lite->new(
                From    => 'root@' . $host,
                To      => $to,
                Subject => $subject,
                Type    => 'multipart/mixed'
            );

            # Set the body
            $email->attach(
                Type     => 'TEXT',
                Data     => $email_body
            );

            # Add attachment:
            $email->attach(
                Type     => 'text/plain; charset=UTF-8',
                Path     => $cpu_msg_file,
                Filename => 'system_snapshot.txt',
                Disposition => 'attachment'
            );

            # Send email
            $email->send;
            print "Sending mail with attachment\n" if ($DEBUG_ME);
        }
    }
}

if (-f $cpu_msg_file) {
    system("rm -f $cpu_msg_file");
}

$cce->bye();

sub do_monitor {
    my ($object) = @_;

    my $name = $object->{NAMESPACE};
    my $oldState = $object->{currentState};

    if ($DEBUG_ME) {
        $oldState = "G";                
    }

    print "monitor [${name}]\n" if ($DEBUG_ME);
    my $type = $object->{type};
    my $am = $object->{typeData};
    my ($ret, $state) = ("", -1);
    $ENV{greenMsg} = $object->{greenMsg} if (defined ($object->{greenMsg}));
    $ENV{yellowMsg} = $object->{yellowMsg} if (defined ($object->{yellowMsg}));
    $ENV{redMsg} = $object->{redMsg} if (defined ($object->{redMsg}));
    if ($type eq "tcp") {
        my $hex;
        $hex = sprintf("%04X", 0 + $am);
        if (grep(/$hex/, @tcp_ports) > 0) {
            $state = 1; # green
            $ret = $ENV{greenMsg};
        }
        else {
            my $restart;
            $state = 3; # red
            $ret = $ENV{redMsg};
            $restart = $object->{restart} if (defined ($object->{restart}));
            system($restart);
        }
    }
    elsif ($type eq "udp") {
        my $hex;
        $hex = sprintf("%04X", 0 + $am);
        if (grep(/$hex/, @udp_ports) > 0) {
            $state = 1; # green
            $ret = $ENV{greenMsg};
        }
        else {
            my $restart;
            $state = 3; # red
            $ret = $ENV{redMsg};
            $restart = $object->{restart} if (defined ($object->{restart}));
            system($restart);
        }
    }
    elsif ( -x $am ) {
        print "running $am\n" if ($DEBUG_ME);
        $ret = `PATH=/bin:/sbin:/usr/sbin:/usr/bin LANG=$system_lang $am`;
        $state = $? >> 8;
    }
    if ($state >= 0) {
        $cce->set ($oid[0], $name, {
            currentState => $states[$state],
            lastChange=>time(),
            currentMessage => $ret,
            });
    }

    # Update $globalState:
    if (($globalState ne "R") && ($globalState ne "Y") && ($statecodes[$state] eq "G")) {
        $globalState = "G";
    }
    if (($globalState ne "R") && ($statecodes[$state] eq "Y")) {
        $globalState = "Y";
    }        
    if ($statecodes[$state] eq "R") {
        $globalState = "R";
    }
    print "Globalstate: $globalState\n" if ($DEBUG_ME);

    print "OldState:$oldState\tNewState:$statecodes[$state]\n" if ($DEBUG_ME);

    my $msg;
    my $cpu_msg;
    if (($oldState ne $statecodes[$state]) && !($oldState eq "N" && $statecodes[$state] eq "G")) {
        print "Status changed\n" if ($DEBUG_ME);
        $msg = $i18n->get($ret);

        ### Start: Append 'top' output if CPU is/was in moderate or heavy useage:
        if (($object->{nameTag} eq "[[base-am.amCPUName]]") && ($object->{monitor} == "1")) {
            if ($object->{currentMessage}) {
                print "CPU is/was in moderate or heavy useage - generating 'top' report\n" if ($DEBUG_ME);

                if ((-e '/usr/sausalito/sbin/incus_load') && (-e '/usr/bin/incus')) {
                    $cpu_msg .= "\n\n";
                    $cpu_msg .= `/usr/sausalito/sbin/incus_load`;
                }

                my $TOP = `top -b -w 512 -n 1 -c -o %CPU`;
                my @procs = split(/\n/, $TOP);
                $cpu_msg .= "\n\n-------------------------------------------\n";
                $cpu_msg .= "System snapshot:\n";
                $cpu_msg .= "-------------------------------------------\n";
                $cpu_msg .= $TOP . "\n";
                $cpu_msg .= "\n\n";
                print "TOP: \n $TOP \n" if ($DEBUG_ME);
                $msg .= $cpu_msg;

                open(FH, '>', $cpu_msg_file) or die $!;
                print FH $cpu_msg;
                close(FH);
                system("chown root:root $cpu_msg_file");
                system("chmod 0600 $cpu_msg_file");
            }
        }
        ### End: Append 'top' output if CPU is/was in moderate or heavy useage:
    }
    return ($state, $ret, $msg);
}
