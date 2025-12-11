#!/usr/local/cpanel/3rdparty/bin/perl
# BackBork KISS - cPanel Transport Helper
# Bridges PHP to cPanel's internal Cpanel::Transport::Files module
#
# BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
# Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
# https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Usage:
#   cpanel_transport.pl --action=ls --transport=<id> [--path=<path>]
#   cpanel_transport.pl --action=delete --transport=<id> --path=<path>
#
# Output: JSON to stdout

use strict;
use warnings;

# Use cPanel's Perl libraries
use lib '/usr/local/cpanel';

use Cpanel::JSON            ();
use Cpanel::Backup::Transport();
use Cpanel::Transport::Files();

# Parse command line arguments
my %args;
foreach my $arg (@ARGV) {
    if ($arg =~ /^--(\w+)=(.*)$/) {
        $args{$1} = $2;
    }
}

# Validate required arguments
my $action = $args{'action'} || '';
my $transport_id = $args{'transport'} || '';

if (!$action) {
    print_json({ success => 0, message => 'Missing required argument: --action' });
    exit 1;
}

if (!$transport_id) {
    print_json({ success => 0, message => 'Missing required argument: --transport' });
    exit 1;
}

# Get transport configuration from WHM backup config
my $transport_config = get_transport_config($transport_id);
if (!$transport_config) {
    print_json({ success => 0, message => "Transport '$transport_id' not found" });
    exit 1;
}

# Execute requested action
if ($action eq 'ls') {
    do_ls($transport_config, $args{'path'} || '');
}
elsif ($action eq 'delete') {
    my $path = $args{'path'} || '';
    if (!$path) {
        print_json({ success => 0, message => 'Missing required argument: --path for delete' });
        exit 1;
    }
    do_delete($transport_config, $path);
}
else {
    print_json({ success => 0, message => "Unknown action: $action" });
    exit 1;
}

exit 0;

# ============================================================================
# TRANSPORT CONFIGURATION
# ============================================================================

sub get_transport_config {
    my ($id) = @_;
    
    warn "cpanel_transport.pl: Looking for transport ID: $id\n";
    
    # Use Cpanel::Backup::Transport to get enabled destinations
    my $transports = Cpanel::Backup::Transport->new();
    my $transport_configs = $transports->get_enabled_destinations();
    
    if (!$transport_configs) {
        warn "cpanel_transport.pl: Failed to get transport configs\n";
        return;
    }
    
    # Log available destination IDs for debugging
    my @available_ids = keys %$transport_configs;
    warn "cpanel_transport.pl: Available destination IDs: " . join(', ', @available_ids) . "\n";
    
    # Find the matching config
    my $config = $transport_configs->{$id};
    
    if (!$config) {
        warn "cpanel_transport.pl: No matching destination found for ID: $id\n";
        return;
    }
    
    warn "cpanel_transport.pl: Found matching destination, type=" . ($config->{'type'} || 'Unknown') . "\n";
    
    return {
        id       => $id,
        type     => $config->{'type'} || 'Unknown',
        host     => $config->{'host'} || '',
        port     => $config->{'port'} || 22,
        path     => $config->{'path'} || '/',
        username => $config->{'username'} || '',
        password => $config->{'password'} || '',
        authtype => $config->{'authtype'} || 'password',
        keyfile  => $config->{'sshkey'} || '',
        timeout  => $config->{'timeout'} || 30,
        passive  => $config->{'passive'} || 0,
    };
}

# ============================================================================
# LIST FILES
# ============================================================================

sub do_ls {
    my ($config, $path) = @_;
    
    my $type = $config->{'type'};
    warn "cpanel_transport.pl: do_ls type=$type path=" . ($path || 'default') . "\n";
    
    # Build options for transport
    my %opts = build_transport_opts($config);
    warn "cpanel_transport.pl: Built opts for host=" . ($opts{'host'} || 'none') . "\n";
    
    eval {
        my $transport = Cpanel::Transport::Files->new($type, \%opts);
        warn "cpanel_transport.pl: Transport object created\n";
        
        # Default to manual_backup directory (where cpbackup_transport uploads go)
        my $list_path = $path || 'manual_backup';
        warn "cpanel_transport.pl: Listing path=$list_path\n";
        
        # Try to list files
        my $response = $transport->ls($list_path);
        warn "cpanel_transport.pl: ls() returned, status=" . ($response ? ($response->{'status'} || 'undef') : 'no response') . "\n";
        
        if ($response && $response->{'status'}) {
            my @files;
            my $data = $response->{'data'} || [];
            
            foreach my $entry (@$data) {
                next unless ref $entry eq 'HASH';
                next if ($entry->{'filename'} || '') =~ /^\.\.?$/;  # Skip . and ..
                
                push @files, {
                    file     => $entry->{'filename'} || '',
                    size     => $entry->{'size'} || 0,
                    type     => $entry->{'type'} || 'file',
                    perms    => $entry->{'perms'} || '',
                };
            }
            
            print_json({ 
                success => 1, 
                files   => \@files,
                path    => $list_path 
            });
        }
        else {
            my $msg = $response ? ($response->{'message'} || 'Unknown error') : 'No response from transport';
            print_json({ success => 0, message => "Failed to list: $msg", files => [] });
        }
    };
    if ($@) {
        print_json({ success => 0, message => "Transport error: $@", files => [] });
    }
}

# ============================================================================
# DELETE FILE
# ============================================================================

sub do_delete {
    my ($config, $path) = @_;
    
    my $type = $config->{'type'};
    
    # Build options for transport
    my %opts = build_transport_opts($config);
    
    eval {
        my $transport = Cpanel::Transport::Files->new($type, \%opts);
        
        # Ensure path is within manual_backup if not already specified
        my $delete_path = $path;
        if ($delete_path !~ m{^manual_backup/}) {
            $delete_path = "manual_backup/$delete_path";
        }
        
        my $response = $transport->delete($delete_path);
        
        if ($response && $response->{'status'}) {
            print_json({ success => 1, message => "Deleted: $delete_path" });
        }
        else {
            my $msg = $response ? ($response->{'message'} || 'Unknown error') : 'No response from transport';
            print_json({ success => 0, message => "Failed to delete: $msg" });
        }
    };
    if ($@) {
        print_json({ success => 0, message => "Transport error: $@" });
    }
}

# ============================================================================
# HELPER FUNCTIONS
# ============================================================================

sub build_transport_opts {
    my ($config) = @_;
    
    my %opts = (
        host    => $config->{'host'},
        path    => $config->{'path'} || '/',
        timeout => $config->{'timeout'} || 30,
    );
    
    # Add port if specified
    $opts{'port'} = $config->{'port'} if $config->{'port'};
    
    # Add authentication based on type
    my $type = $config->{'type'};
    
    if ($type eq 'SFTP') {
        $opts{'username'} = $config->{'username'} if $config->{'username'};
        
        if ($config->{'authtype'} eq 'key' && $config->{'keyfile'}) {
            $opts{'authtype'} = 'key';
            $opts{'key'}      = $config->{'keyfile'};
            $opts{'passphrase'} = $config->{'password'} if $config->{'password'};
        }
        else {
            $opts{'authtype'} = 'password';
            $opts{'password'} = $config->{'password'} if $config->{'password'};
        }
    }
    elsif ($type eq 'FTP') {
        $opts{'username'} = $config->{'username'} if $config->{'username'};
        $opts{'password'} = $config->{'password'} if $config->{'password'};
        $opts{'passive'}  = $config->{'passive'} ? 1 : 0;
    }
    
    return %opts;
}

sub print_json {
    my ($data) = @_;
    print Cpanel::JSON::Dump($data) . "\n";
}
