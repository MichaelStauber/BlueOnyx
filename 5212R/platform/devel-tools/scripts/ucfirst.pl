#!/usr/bin/perl

if (not @ARGV) {
	print "Must specify a string to run ucfirst against!\n";
}

print ucfirst($ARGV[0])

