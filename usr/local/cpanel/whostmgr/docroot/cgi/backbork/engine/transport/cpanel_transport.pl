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

use Cpanel::JSON             ();
use Cpanel::Backup::Config   ();
use Cpanel::Backup::Transport();
use Cpanel::Transport::Files ();

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
    
    # Find the matching config - return it directly (it's already a hashref)
    my $config = $transport_configs->{$id};
    
    if (!$config) {
        warn "cpanel_transport.pl: No matching destination found for ID: $id\n";
        return;
    }
    
    warn "cpanel_transport.pl: Found matching destination, type=" . ($config->{'type'} || 'Unknown') . "\n";
    
    # Return the config directly - Cpanel::Transport::Files expects this exact structure
    return $config;
}

# ============================================================================
# LIST FILES
# ============================================================================

sub do_ls {
    my ($config, $path) = @_;
    
    my $type = $config->{'type'};
    my $base_path = $config->{'path'} || '';
    warn "cpanel_transport.pl: do_ls type=$type path=" . ($path || 'default') . "\n";
    warn "cpanel_transport.pl: config path (base) = $base_path\n";
    
    eval {
        # Pass config hashref directly to Transport::Files - it expects this exact structure
        my $transport = Cpanel::Transport::Files->new($type, $config);
        warn "cpanel_transport.pl: Transport object created\n";
        
        # Build full path: base_path + manual_backup (where cpbackup_transport uploads)
        # The transport does NOT auto-prepend the config path, we must do it ourselves
        my $manual_backup_path = $base_path ? "$base_path/manual_backup" : "manual_backup";
        
        # Paths to try - manual_backup under base path first, then base path itself
        my @paths_to_try;
        if ($path) {
            # If explicit path given, prepend base_path
            @paths_to_try = ($base_path ? "$base_path/$path" : $path);
        } else {
            # Default: try manual_backup under base, then base itself
            @paths_to_try = ($manual_backup_path);
            push @paths_to_try, $base_path if $base_path;
        }
        
        my $response;
        my $list_path;
        
        for my $try_path (@paths_to_try) {
            $list_path = $try_path;
            warn "cpanel_transport.pl: Trying to list path='$list_path'\n";
            
            eval {
                $response = $transport->ls($list_path);
                warn "cpanel_transport.pl: ls() raw response: " . (ref $response || 'not a ref') . "\n";
                if ($response && ref $response eq 'Cpanel::Transport::Response::ls') {
                    warn "cpanel_transport.pl: response success=" . ($response->{'success'} || 0) . "\n";
                }
            };
            
            # If we got a successful response, we're good
            if ($response && $response->{'success'}) {
                warn "cpanel_transport.pl: ls() succeeded for path='$list_path'\n";
                last;
            }
            
            # Check for PathNotFound exception
            if ($@ && $@ =~ /PathNotFound/) {
                warn "cpanel_transport.pl: Path '$list_path' not found, trying next...\n";
                $response = undef;
                next;
            }
            elsif ($@) {
                warn "cpanel_transport.pl: Error listing '$list_path': $@\n";
            }
        }
        
        warn "cpanel_transport.pl: ls() final success=" . ($response ? ($response->{'success'} || 0) : 'no response') . "\n";
        
        if ($response && $response->{'success'}) {
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
            my $msg = $response ? ($response->{'msg'} || 'Unknown error') : 'Path not found or empty';
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
    my $base_path = $config->{'path'} || '';
    
    warn "cpanel_transport.pl: do_delete called with path='$path', base_path='$base_path'\n";
    
    eval {
        # Pass config hashref directly to Transport::Files
        my $transport = Cpanel::Transport::Files->new($type, $config);
        
        # Use the path as-is - PHP has already built the full path
        # Only add prefixes if the path appears to be just a filename
        my $delete_path = $path;
        
        # Check if path already includes base_path or manual_backup
        my $has_base = $base_path && $delete_path =~ m{^\Q$base_path\E/};
        my $has_manual = $delete_path =~ m{manual_backup/};
        
        # If path is just a filename (no slashes), build the full path
        if ($delete_path !~ m{/}) {
            $delete_path = "manual_backup/$delete_path";
            warn "cpanel_transport.pl: Added manual_backup prefix -> '$delete_path'\n";
            
            if ($base_path) {
                $delete_path = "$base_path/$delete_path";
                warn "cpanel_transport.pl: Added base_path prefix -> '$delete_path'\n";
            }
        }
        
        warn "cpanel_transport.pl: Final delete path='$delete_path'\n";
        
        my $response = $transport->delete($delete_path);
        
        if ($response && $response->{'success'}) {
            print_json({ success => 1, message => "Deleted: $delete_path" });
        }
        else {
            my $msg = $response ? ($response->{'msg'} || 'Unknown error') : 'No response from transport';
            print_json({ success => 0, message => "Failed to delete: $msg" });
        }
    };
    if ($@) {
        my $error = $@;
        # Extract message from Cpanel::Transport::Exception objects
        if (ref($error) && $error->can('get')) {
            $error = $error->get('msg') || $error->get('message') || "$error";
        }
        elsif (ref($error) && ref($error) eq 'HASH') {
            $error = $error->{'msg'} || $error->{'message'} || "$error";
        }
        elsif (ref($error)) {
            # Try to stringify or get any useful info
            $error = eval { $error->message } || eval { $error->to_string } || "$error";
        }
        print_json({ success => 0, message => "Transport error: $error" });
    }
}

# ============================================================================
# HELPER FUNCTIONS
# ============================================================================

sub print_json {
    my ($data) = @_;
    print Cpanel::JSON::Dump($data) . "\n";
}
