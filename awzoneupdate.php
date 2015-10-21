#!/usr/bin/php
<?php
	/* (c)2014 Gulf Interstate Engineering <mlott@gie.com>
	 *
	 * Push local changes in zone files out to Amazon Route53
	 * Requires the AWS SDK for PHP (aws.phar) from:
	 *   http://docs.aws.amazon.com/aws-sdk-php/guide/latest/
	 *
	 * This program is free software; you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation; either version 2 of the License, or
	 * (at your option) any later version.
	 *
	 * This program is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU General Public License for more details.
	 *
	 * You should have received a copy of the GNU General Public License
	 * along with this program; if not, write to the Free Software
	 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
	 *
	 * Usage:
	 *  awzoneupdate.php $DOMAIN (Display array values only)
	 *    or
	 *  awzoneupdate.php $DOMAIN 1 (to actually do it)
	 *    or
	 *  awzoneupdate.php $DOMAIN ADD (to add a new domain)
	 */

	/* Include either the autoloader from aws.zip, or simply aws.phar. */
	//require_once('/usr/local/aws/aws-autoloader.php');
	require_once('/usr/local/aws/aws.phar');
	use Aws\Route53\Route53Client;

	/* Set global vars */
	$ttl = '300';
	$basedir = '/var/named';
	$doit = False;

	/* Convert object with protected data to unprotected array
	 * from http://stackoverflow.com/questions/4345554/convert-php-object-to-associative-array
	 */
	function dismount($object)
	{
		$reflectionClass = new ReflectionClass(get_class($object));
		$array = array();
		foreach($reflectionClass->getProperties() as $property)
		{
			$property->setAccessible(true);
			$array[$property->getName()] = $property->getValue($object);
			$property->setAccessible(false);
		}
		return $array;
	}

	function updatemxrecord($data,$zoneid,$domain,$action='UPSERT')
	{
		$res = $GLOBALS['client']->changeResourceRecordSets(array(
			'HostedZoneId' => $zoneid,
			'ChangeBatch' => array(
				'Comment' => 'PHP test',
				'Changes' => array(
					array(
						'Action' => $action,
						'ResourceRecordSet' => array(
							'Name' => $data['name'],
							'Type' => $data['type'],
							'TTL'  => $GLOBALS['ttl'],
							'ResourceRecords' => $data['mx']
						)
					)
				)
			)
		));
	}
	function updaterecord($data,$zoneid,$action='UPSERT')
	{
		$res = $GLOBALS['client']->changeResourceRecordSets(array(
			'HostedZoneId' => $zoneid,
			'ChangeBatch' => array(
				'Comment' => 'PHP test',
				'Changes' => array(
					array(
						'Action' => $action,
						'ResourceRecordSet' => array(
							'Name' => $data['name'],
							'Type' => $data['type'],
							'TTL'  => $GLOBALS['ttl'],
							'ResourceRecords' => array(
								array(
									'Value' => $data['value']
								)
							)
						)
					)
				)
			)
		));
	}

	function showopt($string='')
	{
		echo "${string}Options are:\n"
			. "\t'awzoneupdate.php {DOMAIN}'\t(To view the array of changes to be sent)\n"
			. "\t'awzoneupdate.php {DOMAIN} 1'\t(To commit those changes)\n"
			. "\t'awzoneupdate.php {DOMAIN} ADD'\t(To add a new domain to Route53)\n\n";
	}

	/* Domain from command line - same as name of zone file. */
	$domain  = @$argv[1];
	if(@empty($domain))
	{
		showopt('You must supply a domain name!  ');
		exit;
	}

	$domdot  = $domain . '.';
	$addzone = @$argv[2] == 'ADD' ? True : False;
	$doit = @isset($argv[2]) ? True : $doit;

	/* Load profile named route53 from ~/.aws/credentials (could be named anything)
	 * Also, connect to it.
	 */
	$client = Route53Client::factory(array('profile' => 'route53'));

	if($addzone === True)
	{
		$client->createHostedZone(array(
			'Name' => $domdot,
			'CallerReference' => uniqid($domain,True)
		));
		/* Create array from object listing all zones */
		$res = $client->listHostedZones(array('MaxItems' => '50'));
		$obj = dismount($res);

		foreach($obj['data']['HostedZones'] as $x => $data)
		{
			$name = trim($data['Name'],'.');
			$zones[$name] = array(
				'id'   => $data['Id'],
				'name' => $data['Name']
			);
		}
		$zoneid = $zones[$domain]['id'];

		echo "Domain $domain added with zoneid of $zoneid\n";
		exit;
	}
	else
	{
		/* Create array from object listing all zones */
		$res = $client->listHostedZones(array('MaxItems' => '50'));
		$obj = dismount($res);

		foreach($obj['data']['HostedZones'] as $x => $data)
		{
			$name = trim($data['Name'],'.');
			$zones[$name] = array(
				'id'   => $data['Id'],
				'name' => $data['Name']
			);
		}
	}
	/* Can we find a Route53 zoneid for the zone we want to work on? */
	if(@isset($zones[$domain]['id']))
	{
		$zoneid = $zones[$domain]['id'];
	}
	else
	{
		showopt("Zone for $domain does not yet exist on Route53!  ");
		exit;
	}
	$record = array();
	$insoa = False;
	$soa_pos = 0;
	/* Used to set order of extraction from zone file */
	$soa_parts = array('serial','refresh','retry','expire','minimum');

	/* Read zone file to import */
	if(@stat($basedir . '/' . $domain))
	{
		$data = file($basedir . '/' . $domain);
	}
	else
	{
		echo "No zone file for $domain in $basedir!\n";
		exit;
	}

	/* Loop through each line of the zone file */
	foreach($data as $x => $line)
	{
		$test = rtrim($line);
		//echo "Checking LINE: $test\n";
		if($insoa)
		{
			if(preg_match('/^\s+\)$/',$test))
			{
				/* END OF SOA, copy data into value field */
				$record[$domdot]['value'] = $record[$domdot]['soa'] . ' '
					. $record[$domdot]['email'] . ' '
					. $record[$domdot]['serial'] . ' '
					. $record[$domdot]['refresh'] . ' '
					. $record[$domdot]['retry'] . ' '
					. $record[$domdot]['expire'] . ' '
					. $record[$domdot]['minimum'] . ' ';
				$insoa = False;
			}
			elseif(preg_match('/^\s+(.*?);\s+\w*(.*?)$/',$test,$matches))
			{
				/* PART OF SOA - assume order of soa parts, which is typical for a zone file */
				$record[$domdot][$soa_parts[$soa_pos]] = trim($matches[1]);
				$soa_pos++;
			}
		}
		else
		{
			if(preg_match('/^(.*?)SOA(.*?)/',$test))
			{
				/* Check for start of SOA */
				if(preg_match('/^(.*?)\s+(.*?)\s+(.*?)\s+(.*?)\s+(.*?)$/',$test,$matches))
				{
					/* START OF SOA DATA, let's extract this line */
					$insoa = True;

					$email = @trim(str_replace('(','',$matches[5]));
					$record[$domdot] = array(
						'name'  => $domdot,
						'type'  => 'SOA',
						'soa'   => $matches[4],
						'value' => '',
						'email' => $email
					);
				}
			}
			elseif(preg_match('/^;(.*?)/',$test) || preg_match('/^\$(.*?)/',$test))
			{
				/* Comment or global ORIGIN, TTL, etc. line, skipping */
				continue;
			}
			elseif(preg_match('/^\s+(.*?)MX(.*?)\s+(.*?)$/',$test,$matches))
			{
				/* Match all MX records into their own special location */
				@$record[$domdot]['mx'][] = array(
					'Value' => trim($matches[3])
				);
			}
			elseif(preg_match('/^\s+(.*?)TXT(.*?)\s+(.*?)$/',$test,$matches))
			{
				/* Match all TXT records into their own special location */
				$record[$domdot]['txt'][] = array(
					'name'  => $domdot,
					'type'  => 'TXT',
					'value' => trim($matches[3])
				);
			}
			elseif(preg_match('/^(.*?)\s+(.*?)\s+(.*?)$/',$test,$matches))
			{
				/* Typical record (non-SOA, non-comment, etc.) */
				if(@$matches[1])
				{
					$record[$matches[1]] = array(
						'name'  => $matches[1] . '.' . $domdot,
						'type'  => $matches[2],
						'value' => $matches[3]
					);
				}
			}
		}
	}

	if($doit === True)
	{
		/* First handle each of the MX and TXT records
		 * Could also add NS records, etc. to this script.
		 */
		if(@isset($record[$domdot]['mx'][0]))
		{
			$mxout = array(
				'name' => $domdot,
				'type' => 'MX',
				'mx'   => $record[$domdot]['mx']
			);
			updatemxrecord($mxout,$zoneid,'UPSERT');
		}
		if(@isset($record[$domdot]['txt'][0]))
		{
			foreach($record[$domdot]['txt'] as $data)
			{
				updaterecord($data,$zoneid,'UPSERT');
			}
		}
		/* Now handle standard A, CNAME, etc. */
		foreach($record as $data)
		{
			if($data['type'] == 'D')
			{
				/* Not used */
				updaterecord($data,$zoneid,'DELETE');
			}
			else
			{
				updaterecord($data,$zoneid,'UPSERT');
			}
		}
	}
	else
	{
		print_r($record);exit;
	}
