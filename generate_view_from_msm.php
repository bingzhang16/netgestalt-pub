<?php

function create_msm($msm_file_param, $orig_cct_name_param){
	global $debug,$debugout;
	global $cmd,$user_int_dir,$user_int_track_dir,$root;

	if($debug){
		fwrite($debugout, $msm_file_param."\n");
		fwrite($debugout, $orig_cct_name_param."\n");
	}
	$msm_file=$msm_file_param;
	$orig_cct_name=$orig_cct_name_param;
	// print "{\"status\":\"OK\",\"msm\":\"".$msm."\"}";
	$fn=get_network_name($root."/".$user_int_dir);
  if(count($fn)==2){
		$user_network_name=$orig_cct_name;
  }
	else if(count($fn)==3){
		$user_network_name=$orig_cct_name."_".$fn[2];
	}
	$network_names=$fn[0];
	if(!is_dir($fn[1])){
		mkdir($fn[1], 0755, true);
	}
	// the msm file is generated by R script, the format "should" be OK, but check it anyway
	$fh=fopen($msm_file,"r");
	if(!$fh){
    print("{\"create_network_status\":{\"status\":\"cannot open msm file\"}}");
    exit;
	} 
	// check if the file has at least two sections;
	$ruler_exist=FALSE;
	$hmi_exist=FALSE;
	$network_exist=FALSE;
	$cct_exist=FALSE;
	$tsi_exist=FALSE;
  $mapping_exist=FALSE;
	$section_count=0;
  $current_mapping=0; // default set to 0 (has mapping)
	while(!feof($fh)){
		$line=fgets($fh);
		if(preg_match("/^(#)+\s*ruler/i",$line)){
			$ruler_exist=TRUE;
		}
		if(preg_match("/^(#)+\s*hmi/i",$line)){
			$hmi_exist=TRUE;
		}
		if(preg_match("/^(#)+\s*network/i",$line)){
			$network_exist=TRUE;
		}
		if(preg_match("/^(#)+\s*Expression/i",$line)){
			$cct_exist=TRUE;
		}
		if(preg_match("/^(#)+\s*Sample/i",$line)){
			$tsi_exist=TRUE;
		}
		if(preg_match("/^(#)+\s*mapping status/i",$line)){
		  $mapping_exist=TRUE;	
      // read next line
      $line=trim(fgets($fh));
      $items=explode("=",$line);
      $current_mapping=$items[1];
		}
	}
// mapping_exist is optional, if missing, set to 0 for backward compatibility
	if(!($ruler_exist && $hmi_exist && $network_exist && $cct_exist)){
    print("{\"create_network_status\":{\"status\":\"msm file should contain at leaset: ruler, hmi, network, expression data sections.\"}}");
		exit;
	}
	rewind($fh);
	$current_section=0;
	# open files for write
	$output_fh=array();
	$rul_fh=fopen($fn[1]."/".$fn[0].".rul", "w");
	array_push($output_fh, $rul_fh);
	$hmi_fh=fopen($fn[1]."/".$fn[0].".hmi", "w");
	array_push($output_fh, $hmi_fh);
	$net_fh=fopen($fn[1]."/".$fn[0].".net","w");
	array_push($output_fh, $net_fh);
	$cct_fh=fopen($user_int_track_dir."/".$fn[0].".cct","w");
	array_push($output_fh, $cct_fh);
	if($tsi_exist){
		$tsi_fh=fopen($user_int_track_dir."/".$fn[0].".tsi","w");
		array_push($output_fh, $tsi_fh);
	}
	while(!feof($fh)){
		$line=fgets($fh);
		if(preg_match("/^(#)+/", $line)){
			$current_section++;
			continue;
		}
		fwrite($output_fh[$current_section-1], $line);
	}
	fclose($fh);
	fclose($rul_fh);
	fclose($hmi_fh);
	fclose($net_fh);
	fclose($cct_fh);
	if($tsi_exist){
		fclose($tsi_fh);
	}
	// copy the original file if user later want to download
	//exec("cp ".$root."/".$msm_file." ".$fn[1]."/".$fn[0].".msm");
	exec("cp ".$msm_file." ".$fn[1]."/".$fn[0].".msm");
	if($debug){
		fwrite($debugout, "cp ".$msm_file." ".$fn[1]."/".$fn[0].".msm"."\n");
	}
	exec("chmod 644 ".$fn[1]."/".$fn[0].".msm");
	// generate network 
	$cmd.=" --mapping_status=".$current_mapping." --usernetwork --network=";
	$cmd=$cmd.$fn[0].",".$user_network_name." -r ".$root." --view=network_view";
	$output=array();
	if($debug){
		fwrite($debugout, $cmd."\n");
	}
	exec($cmd,$output);
	// error checking
	$output_string="{\"status\":\"OK\",";
	$output_string=$output_string."\"create_network_status\":{\"status\":\"OK\",".$output[0]."},";
	if($debug){
		fwrite($debugout, $output_string);
	}
	// generate cct track
	$current_network=$fn[0];
	$cmd="scripts/prepare_tracks.py";
	$cmd=$cmd." --usertrack --usernetwork --network=";
	$track_file_name=$fn[0].".cct";
  if(count($fn)==2){
	  $track_label=$orig_cct_name;
  }
	else if(count($fn)==3){
	  $track_label=$orig_cct_name."_".$fn[2];  // same as the new network name
	}
	$cmd=$cmd.$current_network." --track=".$track_file_name." --tracklabel=".$track_label." --tracktype=cct -r ".$root;
  if($tsi_exist){
    $cmd=$cmd." --sampleinfo";
  }
	$output=array();
	exec($cmd,$output);
	if($debug){
		fwrite($debugout, "\n".$cmd."\n");
	}
	$output_string=$output_string."\"create_track_status\":{\"status\":\"OK\",\"type\":\"".$output[0]."\",";
	$output_string.="\"url\":[";
	$output_string=$output_string."\"".$output[1]."\"";
	$output_string.="],\"int_url\":[";
	$output_string=$output_string."\"".$output[2]."\"";
	$output_string.="],\"name\":[";
	$output_string=$output_string."\"".$output[3]."\"";
	$output_string.="]";
  if($tsi_exist){
	  $output_string.=",\"sampleinfo\":[1]";
  }
	$output_string.=",\"samples\":[";
	$output_string=$output_string."\"".$output[4]."\"]";
	$output_string.="}}";
	if($debug){
		fwrite($debugout, $output_string);
	}
	print $output_string;
}
?>