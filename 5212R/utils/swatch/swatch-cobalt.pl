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
        # Export scalar CCE properties so AM scripts see thresholds (rThreshhold, ioYellow, ...)
        foreach my $k (keys %$object) {
            next unless defined $object->{$k};
            next if ref $object->{$k};
            next if $k !~ /^[A-Za-z_][A-Za-z0-9_]*$/;
            next if $object->{$k} =~ /[\r\n]/;
            $ENV{$k} = $object->{$k};
        }
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

                $cpu_msg .= "\n\n";
                $cpu_msg .= io_snapshot();

                if ((-e '/usr/sausalito/sbin/incus_load') && (-e '/usr/bin/incus')) {
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

# Cheap I/O snapshot for CPU AM emails: 1s /proc/stat sample, D-state, diskstats.
sub io_snapshot {
    my $out = '';
    $out .= "-------------------------------------------\n";
    $out .= "I/O wait snapshot:\n";
    $out .= "-------------------------------------------\n";

    my $read_cpu = sub {
        if (open my $fh, '<', '/proc/stat') {
            while (<$fh>) {
                if (/^cpu\s+/) {
                    my @f = split;
                    close $fh;
                    return map { $_ || 0 } @f[1 .. 8];
                }
            }
            close $fh;
        }
        return (0, 0, 0, 0, 0, 0, 0, 0);
    };

    my @a = $read_cpu->();
    select(undef, undef, undef, 1.0);
    my @b = $read_cpu->();
    my @d;
    my $tot = 0;
    for my $i (0 .. 7) {
        $d[$i] = ($b[$i] || 0) - ($a[$i] || 0);
        $d[$i] = 0 if $d[$i] < 0;
        $tot += $d[$i];
    }
    $tot = 1 if $tot < 1;
    my $pct = sub { sprintf('%.1f', ($_[0] * 100) / $tot) };
    my $us = $pct->($d[0] + $d[1]);
    my $sy = $pct->($d[2] + $d[5] + $d[6]);
    my $id = $pct->($d[3]);
    my $wa = $pct->($d[4]);
    my $st = $pct->($d[7]);

    my $blocked = 0;
    if (open my $fh, '<', '/proc/stat') {
        while (<$fh>) {
            $blocked = $1 if /^procs_blocked\s+(\d+)/;
        }
        close $fh;
    }

    my ($l1, $l5, $l15) = ('?', '?', '?');
    if (open my $fh, '<', '/proc/loadavg') {
        my $load = <$fh>;
        close $fh;
        ($l1, $l5, $l15) = split(/\s+/, $load) if defined $load;
    }

    $out .= "cpu (1s): us=$us sy=$sy id=$id wa=$wa st=$st\n";
    $out .= "loadavg: $l1 $l5 $l15   blocked=$blocked\n";

    my $ps = `ps -eo pid,stat,wchan:24,comm --no-headers 2>/dev/null`;
    my @dlines;
    for my $line (split /\n/, $ps) {
        next unless $line =~ /^\s*\d+\s+\S*D/;
        push @dlines, $line;
        last if @dlines >= 15;
    }
    if (@dlines) {
        $out .= "D-state (uninterruptible I/O wait):\n";
        $out .= sprintf("  %-8s %-6s %-24s %s\n", 'PID', 'STAT', 'WCHAN', 'CMD');
        for my $line (@dlines) {
            $out .= "  $line\n";
        }
    }
    else {
        $out .= "D-state: none\n";
    }

    my $uptime_s = 1;
    if (open my $fh, '<', '/proc/uptime') {
        my $l = <$fh>;
        close $fh;
        ($uptime_s) = split(/\s+/, $l) if defined $l;
        $uptime_s = 1 if !$uptime_s || $uptime_s < 1;
    }

    my @disks;
    if (open my $fh, '<', '/proc/diskstats') {
        while (<$fh>) {
            my @f = split;
            next unless @f >= 14;
            my $name = $f[2];
            next if $name =~ /^(loop|ram|sr|fd)/;
            next if $name =~ /^(sd[a-z]|vd[a-z]|hd[a-z]|xvd[a-z])\d+$/;
            next if $name =~ /^nvme\d+n\d+p\d+$/;
            my $reads  = $f[3]  || 0;
            my $rtime  = $f[6]  || 0;
            my $writes = $f[7]  || 0;
            my $wtime  = $f[10] || 0;
            my $iotime = $f[12] || 0;
            next if ($reads + $writes) < 100;
            my $r_await = $reads  ? sprintf('%.1f', $rtime  / $reads)  : '0.0';
            my $w_await = $writes ? sprintf('%.1f', $wtime  / $writes) : '0.0';
            my $util    = sprintf('%.1f', ($iotime / ($uptime_s * 1000)) * 100);
            push @disks, sprintf("  %-12s r_await=%6sms  w_await=%6sms  util~=%6s%%  (since boot)",
                $name, $r_await, $w_await, $util);
        }
        close $fh;
    }
    if (@disks) {
        $out .= "Disk latency (since boot):\n";
        $out .= join("\n", @disks) . "\n";
    }
    $out .= "\n";
    return $out;
}

