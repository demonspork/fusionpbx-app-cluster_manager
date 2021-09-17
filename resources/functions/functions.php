<?php
require __DIR__."/../aws-autoloader.php";
use Aws\Route53\Route53Client;
use function GuzzleHttp\json_decode;

function reload_kamailio() {
    global $kamreload;

    $url = $_SESSION['cluster_manager']['api_url']['text']."/kamailio/reload";
    
    $headers = array(
        "Accept: application/json",
        "Authorization: Bearer ".$_SESSION['cluster_manager']['api_password']['text'],
        'Content-Type: application/json',
    );

    $request = curl_init();
    curl_setopt($request, CURLOPT_URL, $url);
    curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($request, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($request, CURLOPT_HEADER, FALSE);
    curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($request);

    if ($response === FALSE) {
        printf("cUrl error (#%d): %s<br>\n", curl_errno($request),
            htmlspecialchars(curl_error($request)));
    }

    $response_array = json_decode($response);
    $kamreload = $response_array->kamreload;


}


function sync_dsiprouter_destinations(array $domains) {

    global $kamreload;

    $nodes_raw = explode(";", $_SESSION['cluster_manager']['node_list']['text']);
	foreach ($nodes_raw as $node) {
		$node = explode(":", $node);
		$nodes[$node['1']] = $node;
	}

    $request = curl_init();

    $headers = array(
        "Accept: application/json",
        "Authorization: Bearer ".$_SESSION['cluster_manager']['api_password']['text'],
        'Content-Type: application/json',
    );

    // CURLOPT_VERBOSE: TRUE to output verbose information. Writes output to STDERR, 
    // or the file specified using CURLOPT_STDERR.
    curl_setopt($request, CURLOPT_VERBOSE, true);

    $verbose = fopen('php://temp', 'w+');
    curl_setopt($request, CURLOPT_STDERR, $verbose);

    curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
    // curl_setopt($request, CURLOPT_POST, true);
    curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($request, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($request, CURLOPT_HEADER, FALSE);
    curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);


    foreach ($domains as $row) { //domains loop
        
        if ($row['checked'] != "true"){
            continue;
        }
        $sql = "select * from v_destinations as dest";
        $sql .= " join v_domains as d";
        $sql .= " on dest.domain_uuid = d.domain_uuid";
        $sql .= " where d.domain_name = :domain_name";
        $sql .= " and destination_type = 'inbound'";
        $sql .= " and destination_enabled::bool = true";
        $parameters['domain_name'] = $row['domain_name'];
        $database = new database;
        $domain_destinations = $database->select($sql, $parameters, 'all');
        unset($sql, $parameters);

        foreach ($domain_destinations as $destination) { //destinations loop

            $selected_node_endpoint = $nodes[$row['selected_node']]['2'];

            $body = array(
                "did" => $destination['destination_number'],
                "servers" => array ( $selected_node_endpoint ),
                "name" => $row['domain_name']
            );

            curl_setopt($request, CURLOPT_URL, $_SESSION['cluster_manager']['api_url']['text']."/inboundmapping");
            curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($body));

            $response = curl_exec($request);

            if ($response === FALSE) {
                printf("cUrl error (#%d): %s<br>\n", curl_errno($request),
                    htmlspecialchars(curl_error($request)));
            }

            $response_array = json_decode($response);
            $kamreload = $response_array->kamreload;

            if ($response_array->msg == "Duplicate DID's are not allowed") {
                $body = array(
                    "did" => $destination['destination_number'],
                    "servers" => array ( "67", "69" ),
                    "name" => $row['domain_name']
                );

                $url = $_SESSION['cluster_manager']['api_url']['text']."/inboundmapping"."?did=".$destination['destination_number'];
    
                curl_setopt($request, CURLOPT_URL, $url);
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($body));
                $response = curl_exec($request);
    
                if ($response === FALSE) {
                    printf("cUrl error (#%d): %s<br>\n", curl_errno($request),
                        htmlspecialchars(curl_error($request)));
                }
            }
            
            //header('Content-type: text/javascript');
            //echo json_encode($body);
            // echo $response; 

        }//end foreach destinations loop

    }//end foreach domains loop

    curl_close($request);

    // rewind($verbose);
    // $verboseLog = stream_get_contents($verbose);
    // echo "Verbose information:\n<pre>", htmlspecialchars($verboseLog), "</pre>\n";
    return $verbose;

}

function get_dsiprouter_destinations() {
    global $kamreload;
    $request = curl_init();
    curl_setopt($request, CURLOPT_URL, $_SESSION['cluster_manager']['api_url']['text']."/inboundmapping");
    $headers = array(
        "Accept: application/json",
        "Authorization: Bearer ".$_SESSION['cluster_manager']['api_password']['text'],
     );
    curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($request, CURLOPT_HEADER, FALSE);
    curl_setopt($request, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($request);

    if ($response === FALSE) {
        printf("cUrl error (#%d): %s<br>\n", curl_errno($request),
               htmlspecialchars(curl_error($request)));
    }
    curl_close($request);

    $response_array = json_decode($response);
    $kamreload = $response_array->kamreload;
    
    // header('Content-type: text/javascript');
    // echo $response;
    // exit;

    return $response_array;
}

function dns_status(array $domains, $zone = '/hostedzone/Z1CZRQEKH1BFFA', $credentials = '/etc/fusionpbx/aws-config.php') {
    if ($domains) {
        
        $credentials = require_once($credentials);
        $route53 = new Route53Client($credentials);

        $result = $route53->listResourceRecordSets([ 
            'HostedZoneId' => $zone, 
            'MaxItems' => 300, 
            //'StartRecordName' => $domain_name,
            ]);

        $result_array = $result->get('ResourceRecordSets');

        //Repeat if truncated
        while ($result->get('IsTruncated') == 'true') {
            $next = $result->get('NextRecordName');
            $result = $route53->listResourceRecordSets([ 
                'HostedZoneId' => $zone, 
                'MaxItems' => 300, 
                'StartRecordName' => $next,
                ]);
            $result_array = array_merge($result_array, $result->get('ResourceRecordSets'));
        }

        // header('Content-type: text/javascript');
        // echo json_encode($result_array, JSON_PRETTY_PRINT);
        // exit;

        //Check Each Domain
        foreach ($domains as $domain) {
            //Check Each Record Type
            foreach ($result_array as $record) {
                if ($record['Name'] == "_sip._tls.".$domain['domain_name']."." && $record['Type'] == "SRV") {
                    //echo $record['Name']."<br />";
                    $dns_status[$domain['domain_name']]['tls'] = true;
                    foreach($record['ResourceRecords'] as $value) {
                        $dns_status[$domain['domain_name']]['tls_value'] = $value['Value'];
                    }
                }
                if ($record['Name'] == "_sip._tcp.".$domain['domain_name']."." && $record['Type'] == "SRV") {
                    //echo $record['Name']."<br />";
                    $dns_status[$domain['domain_name']]['tcp'] = true;
                    foreach($record['ResourceRecords'] as $value) {
                        $dns_status[$domain['domain_name']]['tcp_value'] = $value['Value'];
                    }
                }
                if ($record['Name'] == "_sip._udp.".$domain['domain_name']."." && $record['Type'] == "SRV") {
                    //echo $record['Name']."<br />";
                    $dns_status[$domain['domain_name']]['udp'] = true;
                    foreach($record['ResourceRecords'] as $value) {
                        $dns_status[$domain['domain_name']]['udp_value'] = $value['Value'];
                    }
                }
                if ($record['Name'] == $domain['domain_name']."." && ($record['Type'] == "A" || $record['Type'] == "CNAME")) {
                    //echo $record['Name']."<br />";
                    $dns_status[$domain['domain_name']]['A'] = true;
                    foreach($record['ResourceRecords'] as $value) {
                        $dns_status[$domain['domain_name']]['a_value'] = $value['Value'];
                    }
                } 
                //echo $record['Name']."          \\052".strstr($domain['domain_name'], ".")."<br />";
                elseif ($record['Name'] == "\\052".strstr($domain['domain_name'], ".").".") {
                    $dns_status[$domain['domain_name']]['A'] = true;
                    foreach($record['ResourceRecords'] as $value) {
                        $dns_status[$domain['domain_name']]['a_value'] = $value['Value'];
                    }
                }
                
            }
        }

        return $dns_status;

    }
    else {
        return false;
    }
}

function set_dns(array $domains, array $nodes, $credentials = '/etc/fusionpbx/aws-config.php', $zone = '/hostedzone/Z1CZRQEKH1BFFA') {
    if ($domains && $nodes) {
        
        //$zone = $_SESSION['default_settings']['aws']['HostedZoneId']['text'];

        $credentials = require_once($credentials);
        $route53 = new Route53Client($credentials);

        foreach ($domains as $domain) {
            if ($domain['checked'] == 'true') {
                $changes[] = [
                    'Action' => 'UPSERT',
                    'ResourceRecordSet' => [
                        'Name' => $domain['domain_name'],
                        'ResourceRecords' => [
                            [
                                'Value' => $domain['selected_node'],
                            ],
                        ],
                        'TTL' => 60,
                        'Type' => 'CNAME',
                    ],  
                ];
                if ($_SESSION['cluster_manager']['srv_records']['bool'] == 'true') {
                    $changes[] = [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => '_sip._tls.'.$domain['domain_name'],
                            'ResourceRecords' => [
                                [
                                    'Value' => '10 0 5071 '.$domain['selected_node'],
                                ],
                                // [
                                //     'Value' => '30 0 5071 '.$nodes[1],
                                // ],
                            ],
                            'TTL' => 60,
                            'Type' => 'SRV',
                        ],  
                    ];
                    $changes[] = [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => '_sip._tcp.'.$domain['domain_name'],
                            'ResourceRecords' => [
                                [
                                    'Value' => '10 0 5070 '.$domain['selected_node'],
                                ],
                                // [
                                //     'Value' => '30 0 5070 '.$nodes[1],
                                // ],
                            ],
                            'TTL' => 60,
                            'Type' => 'SRV',
                        ],
                    ];
                    $changes[] = [
                        'Action' => 'UPSERT',
                        'ResourceRecordSet' => [
                            'Name' => '_sip._udp.'.$domain['domain_name'],
                            'ResourceRecords' => [
                                [
                                    'Value' => '10 0 5070 '.$domain['selected_node'],
                                ],
                                // [
                                //     'Value' => '30 0 5070 '.$nodes[1],
                                // ],
                            ],
                            'TTL' => 60,
                            'Type' => 'SRV',
                        ],
                    ];
                }// end if for SRV records
            }
        }

        // $request = [
        //     'ChangeBatch' => [
        //         'Changes' => $changes,
        //         'Comment' => 'Created by Decibel DNS API',
        //     ],
        //     'HostedZoneId' => $zone,
        // ];
        // header('Content-type: text/javascript');
        // echo json_encode($request, JSON_PRETTY_PRINT);
        // exit;


        $result = $route53->changeResourceRecordSets([
            'ChangeBatch' => [
                'Changes' => $changes,
                'Comment' => 'Created by Decibel DNS API',
            ],
            'HostedZoneId' => $zone,
        ]);

        return $result;
    }

}

?>