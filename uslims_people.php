<?php

# developer defines
$logging_level = 2;
# end of developer defines

$self = __FILE__;
require "utility.php";

$notes = <<<__EOD
usage: $self {options} {db_config_file}

Utility for comparing newus3.people against dbinstances and updating password

Options

--help               : print this information and exit

--admins             : list userlevel + advancelevel >= 3 in all dbs and exit
--emails             : output all email addresses from every db.people and exit
--db dbname          : restrict results to dbname (can be specified multiple times)
--only-deltas        : only display deltas
--quiet              : minimal output    
--update             : update dbinstance.people fname, lname, email & passwords to match newus3.people


__EOD;

$u_argv = $argv;
array_shift( $u_argv ); # first element is program name

$use_dbs             = [];

$admins              = false;
$only_deltas         = false;
$quiet               = false;
$update              = false;
$emails              = false;

while( count( $u_argv ) && substr( $u_argv[ 0 ], 0, 1 ) == "-" ) {
    switch( $u_argv[ 0 ] ) {
        case "--help": {
            echo $notes;
            exit;
        }
        case "--db": {
            array_shift( $u_argv );
            $use_dbs[] = array_shift( $u_argv );
            break;
        }
        case "--only-deltas": {
            array_shift( $u_argv );
            $only_deltas = true;
            break;
        }
        case "--emails": {
            array_shift( $u_argv );
            $emails = true;
            break;
        }
        case "--update": {
            array_shift( $u_argv );
            $update = true;
            break;
        }
        case "--admins": {
            array_shift( $u_argv );
            $admins = true;
            break;
        }
        case "--quiet": {
            array_shift( $u_argv );
            $quiet = true;
            break;
        }
      default:
        error_exit( "\nUnknown option '$u_argv[0]'\n\n$notes" );
    }
}

$config_file = "db_config.php";
if ( count( $u_argv ) ) {
    $use_config_file = array_shift( $u_argv );
} else {
    $use_config_file = $config_file;
}

if ( count( $u_argv ) ) {
    echo $notes;
    exit;
}

if ( !file_exists( $use_config_file ) ) {
    fwrite( STDERR, "$self:
            $use_config_file does not exist

            to fix:

            cp ${config_file}.template $use_config_file
            and edit with appropriate values
            ")
        ;
    exit(-1);
}

file_perms_must_be( $use_config_file );
require $use_config_file;

# main
$existing_dbs = existing_dbs();

if ( !count( $use_dbs ) ) {
    $use_dbs = $existing_dbs;
} else {
    $db_diff = array_diff( $use_dbs, $existing_dbs );
    if ( count( $db_diff ) ) {
        error_exit( "specified --db not found in database : " . implode( ' ', $db_diff ) );
    }
}

# get people from newus3

open_db();

# email report
if ( $emails ) {
    $emails_found = [];
    foreach ( $use_dbs as $db ) {
        $res = db_obj_result( $db_handle, "select email from $db.people", True );
        while( $row = mysqli_fetch_array($res) ) {
            $emails_found[ strtolower( $row['email'] ) ] = 1;
        }
    }
    ksort( $emails_found );
    echo implode( "\n", array_keys( $emails_found ) ) . "\n";
    exit;
}

$res = db_obj_result( $db_handle, "select * from newus3.people", True );
$newus3_people     = [];
$newus3_byusername = [];
while( $row = mysqli_fetch_array($res) ) {
    if ( !strlen( $row[ 'username' ] ) ) {
        $warnings .= "WARNING: empty username in newus3.people with email '" . $row['email'] . "' : this entry will be ignored\n";
    } else {
        $newus3_people[] = $row;
        $newus3_byusername[ $row[ 'username' ] ] = $row;
    }
}
flush_warnings();



# debug_json( "newus3peopld", $newus3_people );

# list usernames in newus3.people

if ( !$quiet ) {
    $linelen = 109;
    echoline( "=", $linelen );
    echo "newus3.people:\n";
    echoline( '-', $linelen );
    printf(
        "%-30s | %-20s | %-20s | %-30s\n"
        ,"username"
        ,"fname"
        ,"lname"
        ,"email"
        );
    echoline( '-', $linelen );
    $out = [];
    foreach ( $newus3_people as $newus3_person ) {
        $out[] = 
            sprintf(
                "%-30s | %-20s | %-20s | %-30s\n"
                ,$newus3_person[ 'username' ]
                ,$newus3_person[ 'fname' ]
                ,$newus3_person[ 'lname' ]
                ,$newus3_person[ 'email' ]
            );
    }
    sort( $out );
    echo implode( '', $out );
}

# admin report
if ( $admins ) {

    $linelen = 146;
    $header = 
        sprintf(
            "%-30s | %-20s | %-20s | %-40s | %-9s | %-12s\n"
            ,'username'
            ,'fname'
            ,'lname'
            ,'email'
            ,'userlevel'
            ,'advancelevel'
        )
        . echoline( '-', $linelen, false )
        ;

    foreach ( $use_dbs as $db ) {
        $res = db_obj_result( $db_handle, "select * from $db.people", True );
        $out         = [];
        $found_users = [];
        while( $row = mysqli_fetch_array($res) ) {
            if ( $row[ 'userlevel' ] + $row[ 'advancelevel' ] >= 3 ) {
                $out[] =
                    sprintf(
                        "%-30s | %-20s | %-20s | %-40s | %-9s | %-12s\n"
                        ,$row['username']
                        ,$row['fname']
                        ,$row['lname']
                        ,$row['email']
                        ,$row['userlevel']
                        ,$row['advancelevel']
                    );
            }
        }

        echoline( "=", $linelen );
        echo "$db.people:\n";
        echoline( '-', $linelen );
        echo $header;
        sort( $out );
        echo implode( '', $out );
    }

    echoline( "=", $linelen );
    exit;
}

# default behavior : check existance, password and email match
$linelen = 120;
$header = 
    sprintf(
        "%-30s | %-20s %1s | %-20s %1s | %-30s %1s | %2s\n"
        ,'username'
        ,'fname'
        ,''
        ,'lname'
        ,''
        ,'email'
        ,''
        ,'pw'
    )
    . echoline( '-', $linelen, false )
    ;

foreach ( $use_dbs as $db ) {
    $res = db_obj_result( $db_handle, "select * from $db.people", True );
    $out         = [];
    $found_users = [];
    while( $row = mysqli_fetch_array($res) ) {
        if ( array_key_exists( $row[ 'username' ], $newus3_byusername ) ) {
            $found_users[ $row['username'] ] = 1;
            $this_newus3_person = $newus3_byusername[ $row[ 'username' ] ];
            $this_email_diff    = $row[ 'email' ] != $this_newus3_person[ 'email' ];
            $out[] =
                sprintf(
                    "%-30s | %-20s %1s | %-20s %1s | %-30s %1s | %2s\n"
                    ,$row['username']
                    ,$row['fname']
                    ,boolstr( $row[ 'fname' ] != $this_newus3_person[ 'fname' ], "Δ" )
                    ,$row['lname']
                    ,boolstr( $row[ 'lname' ] != $this_newus3_person[ 'lname' ], "Δ" )
                    ,$row['email']
                    ,boolstr( $this_email_diff, "Δ" )
                    ,boolstr( $row[ 'password' ] != $this_newus3_person[ 'password' ], "Δ" )
                );
            if ( $update && strpos( end( $out ), "Δ" ) ) {
                $no_dup_email = true;
                if ( $this_email_diff ) {
                    # extra query to check if we need an update instead
                    $query =
                        "select username from $db.people "
                        . "where email='" . $this_newus3_person[ 'email' ] . "'"
                        ;
                    if ( $this_res = db_obj_result( $db_handle, $query, false, true ) ) {
                        $warning .=
                            sprintf(
                                "WARNING: email not updated when trying to update $db.people for username '%s' : there already exists another username '%s' with the requested email '%s'\n"
                                ,$row['username']
                                ,$this_res->{'username'}
                                ,$this_newus3_person[ 'email' ]
                            );
                        $no_dup_email = false;
                    }
                }
                if ( $no_dup_email ) {
                    $query =
                        "update $db.people set "
                        . "fname='"           . $this_newus3_person[ 'fname' ]    . "'"
                        . ", lname='"         . $this_newus3_person[ 'lname' ]    . "'"
                        . ", email='"         . $this_newus3_person[ 'email' ]    . "'"
                        . ", password='"      . $this_newus3_person[ 'password' ] . "'"
                        . " where username='" . $this_newus3_person[ 'username' ] . "'"
                        ;
                } else {
                    $query =
                        "update $db.people set "
                        . "fname='"           . $this_newus3_person[ 'fname' ]    . "'"
                        . ", lname='"         . $this_newus3_person[ 'lname' ]    . "'"
                        . ", password='"      . $this_newus3_person[ 'password' ] . "'"
                        . " where username='" . $this_newus3_person[ 'username' ] . "'"
                        ;
                }
                db_obj_result( $db_handle, $query );
            }
        }
    }
    foreach ( $newus3_people as $newus3_person ) {
        if ( !array_key_exists( $newus3_person[ 'username' ], $found_users ) ) {
            $out[] =
                sprintf(
                    "%-30s | %-20s %1s | %-20s %1s | %-30s %1s | %2s\n"
                    ,$newus3_person[ 'username' ]
                    ,"missing"
                    ,"Δ"
                    ,"missing"
                    ,"Δ"
                    ,"missing"
                    ,"Δ"
                    ,"Δ"
                );

            if ( $update ) {
                # check if email already exists in another record
                $query =
                    "select username from $db.people"
                    . " where email='"   . $newus3_person[ 'email' ] . "'"
                    . " and ( username is null or  username!='" . $newus3_person[ 'username' ] . "' )"
                    ;
                if ( $this_res = db_obj_result( $db_handle, $query, false, true ) ) {
                    if ( get_yn_answer(
                             sprintf(
                                 "When trying to update $db.people username '%s': there exists username '%s' with email '%s', update this record instead"
                                 ,$newus3_person['username']
                                 ,$this_res->{'username'}
                                 ,$newus3_person[ 'email' ]
                             ) ) ) {
                        $query =
                            "update $db.people set "
                            . "username='"        . $newus3_person[ 'username' ] . "'"
                            . ", fname='"         . $newus3_person[ 'fname' ]    . "'"
                            . ", lname='"         . $newus3_person[ 'lname' ]    . "'"
                            . ", password='"      . $newus3_person[ 'password' ] . "'"
                            . " where email='"    . $newus3_person[ 'email' ]    . "'"
                            ;
                        db_obj_result( $db_handle, $query );
                    }
                } else {
                    $query =
                        "insert into $db.people set "
                        . "personGUID='"     . uuid() . "'"
                        . ", username='"     . $newus3_person[ 'username' ]     . "'"
                        . ", lname='"        . $newus3_person[ 'lname' ]        . "'"
                        . ", fname='"        . $newus3_person[ 'fname' ]        . "'"
                        . ", organization='" . $newus3_person[ 'organization' ] . "'"
                        . ", address='"      . $newus3_person[ 'address' ]      . "'"
                        . ", city='"         . $newus3_person[ 'city' ]         . "'"
                        . ", state='"        . $newus3_person[ 'state' ]        . "'"
                        . ", zip='"          . $newus3_person[ 'zip' ]          . "'"
                        . ", country='"      . $newus3_person[ 'country' ]      . "'"
                        . ", phone='"        . $newus3_person[ 'phone' ]        . "'"
                        . ", email='"        . $newus3_person[ 'email' ]        . "'"
                        . ", password='"     . $newus3_person[ 'password' ]     . "'"
                        . ", userlevel="     . $newus3_person[ 'userlevel' ]
                        . ", activated="     . $newus3_person[ 'activated' ]
                        . ", signup=now()"
                        ;
                    db_obj_result( $db_handle, $query );
                }
            }
        }
    }
    if ( $only_deltas ) {
        $out = preg_grep( '/Δ/', $out );
    }
    if ( count( $out ) ) {
        echoline( "=", $linelen );
        echo "$db.people:\n";
        echoline( '-', $linelen );
        echo $header;
        sort( $out );
        echo implode( '', $out );
    } else {
        if ( !$quiet ) {
            echoline( "=", $linelen );
            echo "$db.people: all ok\n";
        }
    }
    flush_warnings();
}

if ( !$quiet ) {
    echoline( "=", $linelen );
}
