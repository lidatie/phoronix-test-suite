<?php

function pts_recurse_call_benchmark($benchmarks_array, $arguments_array, $save_results = false, &$tandem_xml = "", $results_identifier = "", $arguments_description = "")
{
	for($i = 0; $i < count($benchmarks_array); $i++)
	{
		if(pts_benchmark_type($benchmarks_array[$i]) == "TEST_SUITE")
		{
			$xml_parser = new tandem_XmlReader(file_get_contents(XML_SUITE_DIR . $benchmarks_array[$i] . ".xml"));

			$sub_suite_benchmarks = $xml_parser->getXMLArrayValues("PTSuite/PTSBenchmark/Benchmark");
			$sub_arguments = $xml_parser->getXMLArrayValues("PTSuite/PTSBenchmark/Arguments");
			$sub_arguments_description = $xml_parser->getXMLArrayValues("PTSuite/PTSBenchmark/Description");

			pts_recurse_call_benchmark($sub_suite_benchmarks, $sub_arguments, $save_results, $tandem_xml, $results_identifier, $sub_arguments_description);
		}
		else
		{
			$test_result = pts_run_benchmark($benchmarks_array[$i], $arguments_array[$i], $arguments_description[$i]);

			if($save_results && $test_result != -1)
				pts_record_benchmark_result($tandem_xml, $benchmarks_array[$i], $arguments_array[$i], $results_identifier, $test_result, $arguments_description[$i], pts_request_new_id());

			if($i != (count($benchmarks_array) - 1))
				sleep(pts_read_user_config("PhoronixTestSuite/Options/Benchmarking/SleepTimeBetweenTests", 5));
		}
	}
}
function pts_record_benchmark_result(&$tandem_xml, $benchmark, $arguments, $identifier, $result, $description, $tandem_id = 128)
{
	if($result > -1 && strlen($result) < 12)
	{
		global $BENCHMARK_RAN;

		$xml_parser = new tandem_XmlReader(file_get_contents(XML_PROFILE_DIR . $benchmark . ".xml"));
		$benchmark_title = $xml_parser->getXMLValue("PTSBenchmark/Information/Title");
		$benchmark_version = $xml_parser->getXMLValue("PTSBenchmark/Information/Version");
		$result_scale = $xml_parser->getXMLValue("PTSBenchmark/Information/ResultScale");
		$proportion = $xml_parser->getXMLValue("PTSBenchmark/Information/Proportion");
		$default_arguments = $xml_parser->getXMLValue("PTSBenchmark/Settings/Default/Arguments");

		if(empty($description))
		{
			$default_test_descriptor = $xml_parser->getXMLValue("PTSBenchmark/Information/TestDescriptor");

			if(!empty($default_test_descriptor))
				$description = $default_test_descriptor;
			else if(is_file(BENCHMARK_ENV_DIR . "$benchmark/pts-test-description"))
				$description = @file_get_contents(BENCHMARK_ENV_DIR . "$benchmark/pts-test-description");
			else
				$description = "Phoronix Test Suite v" . PTS_VERSION;
		}
		if(empty($benchmark_version))
		{
			if(is_file(BENCHMARK_ENV_DIR . "$benchmark/pts-test-version"))
				$benchmark_version = @file_get_contents(BENCHMARK_ENV_DIR . "$benchmark/pts-test-version");

		}

		unset($xml_parser);

		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Name", $tandem_id, $benchmark_title);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Version", $tandem_id, $benchmark_version);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Attributes", $tandem_id, $description);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Scale", $tandem_id, $result_scale);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Proportion", $tandem_id, $proportion);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/ResultFormat", $tandem_id, "BAR_GRAPH");
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/TestName", $tandem_id, $benchmark);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/TestArguments", $tandem_id, trim($default_arguments . " " . $arguments));
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Results/Group/Entry/Identifier", $tandem_id, $identifier, 5);
		$tandem_xml->addXmlObject("PhoronixTestSuite/Benchmark/Results/Group/Entry/Value", $tandem_id, $result, 5);

		$BENCHMARK_RAN = true;
	}
}
function pts_save_benchmark_file($PROPOSED_FILE_NAME, &$RESULTS = null, $RAW_TEXT = null)
{
	$j = 1;
	while(is_file(SAVE_RESULTS_DIR . $PROPOSED_FILE_NAME . "/test-$j.xml"))
		$j++;

	$REAL_FILE_NAME = $PROPOSED_FILE_NAME . "/test-" . $j . ".xml";

	if($RESULTS != null)
		$R_FILE = $RESULTS->getXML();
	else if($RAW_TEXT != null)
		$R_FILE = $RAW_TEXT;
	else
		return false;

	pts_save_result($REAL_FILE_NAME, $R_FILE);

	if(!is_file(SAVE_RESULTS_DIR . $PROPOSED_FILE_NAME . "/composite.xml"))
	{
		pts_save_result($PROPOSED_FILE_NAME . "/composite.xml", file_get_contents(SAVE_RESULTS_DIR . $REAL_FILE_NAME));
	}
	else
	{
		// Merge Results
		$MERGED_RESULTS = pts_merge_benchmarks(file_get_contents(SAVE_RESULTS_DIR . $PROPOSED_FILE_NAME . "/composite.xml"), file_get_contents(SAVE_RESULTS_DIR . $REAL_FILE_NAME));
		pts_save_result($PROPOSED_FILE_NAME . "/composite.xml", $MERGED_RESULTS);
	}
	return $REAL_FILE_NAME;
}
function pts_run_benchmark($benchmark_identifier, $extra_arguments = "", $arguments_description = "")
{
	if(!defined("SCREENSAVER_KILLED"))
		shell_exec("xdg-screensaver reset 2>&1");

	if(pts_process_active($benchmark_identifier))
	{
		echo "\nThis benchmark ($benchmark_identifier) is already running... Please wait until the first instance is finished.\n";
		return 0;
	}
	pts_process_register($benchmark_identifier);

	$xml_parser = new tandem_XmlReader(file_get_contents(XML_PROFILE_DIR . "$benchmark_identifier.xml"));
	$execute_binary = $xml_parser->getXMLValue("PTSBenchmark/Information/Executable");
	$benchmark_title = $xml_parser->getXMLValue("PTSBenchmark/Information/Title");
	$times_to_run = intval($xml_parser->getXMLValue("PTSBenchmark/Information/TimesToRun"));
	$pre_run_message = $xml_parser->getXMLValue("PTSBenchmark/Information/PreRunMessage");
	$result_scale = $xml_parser->getXMLValue("PTSBenchmark/Information/ResultScale");
	$arg_identifier = $xml_parser->getXMLArrayValues("PTSBenchmark/Settings/Option/Identifier");
	$execute_path = $xml_parser->getXMLValue("PTSBenchmark/Settings/Default/PossiblePaths");
	$default_arguments = $xml_parser->getXMLValue("PTSBenchmark/Settings/Default/Arguments");

	if(empty($times_to_run) || !is_int($times_to_run))
		$times_to_run = 1;

	if(empty($execute_binary))
		$execute_binary = $benchmark_identifier;

	if(is_file(BENCHMARK_ENV_DIR . "$benchmark_identifier/$execute_binary") || is_link(BENCHMARK_ENV_DIR . "$benchmark_identifier/$execute_binary"))
		$to_execute = BENCHMARK_ENV_DIR . "$benchmark_identifier/";
	else if(is_file($execute_path . $execute_binary) || is_link($execute_path . $execute_binary)) //TODO: Support multiple paths in PossiblePaths separated by : delimiter.
		$to_execute = $execute_path;
	else
	{
		echo "This application executable could not be found in " . $execute_path . ". or " . BENCHMARK_ENV_DIR . "$benchmark_identifier/.\nBenchmark terminating.";
		return;
	}

	if(is_dir(BENCHMARK_ENV_DIR . "$benchmark_identifier/") && file_get_contents(BENCHMARK_ENV_DIR . "$benchmark_identifier/pts-install") != md5_file(BENCHMARK_RESOURCE_DIR . "$benchmark_identifier/install.sh"))
	{
		echo pts_string_header("NOTE: This test installation is out of date.\nFor best results, the $benchmark_title test should be re-installed.");
		// Auto reinstall
		//require_once("pts-core/functions/pts-functions-run.php");
		//pts_install_benchmark($benchmark_identifier);
	}

	$PTS_BENCHMARK_ARGUMENTS = trim($default_arguments . " " . $extra_arguments);
	$BENCHMARK_RESULTS_ARRAY = array();

	if(is_file(BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/pre.sh"))
	{
		echo shell_exec("sh " . BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/pre.sh " . BENCHMARK_ENV_DIR . "$benchmark_identifier");
	}

	if(!empty($pre_run_message))
	{
		echo $pre_run_message . "\n";
		echo "\nHit Any Key To Continue Benchmarking.\n";
		fgets(STDIN);
	}

	for($i = 0; $i < $times_to_run; $i++)
	{
		echo pts_string_header($benchmark_title . " Benchmark (Run " . ($i + 1) . " of " . $times_to_run . ")");
		$result_output = array();

		echo $BENCHMARK_RESULTS = pts_exec("cd $to_execute; ./$execute_binary $PTS_BENCHMARK_ARGUMENTS");

		if(is_file(BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/parse-results.php"))
		{
			$BENCHMARK_RESULTS = pts_exec("cd " . BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/ && php parse-results.php \"$BENCHMARK_RESULTS\"");
		}
		array_push($BENCHMARK_RESULTS_ARRAY, $BENCHMARK_RESULTS);
	}

	if(is_file(BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/post.sh"))
	{
		echo pts_exec("sh " . BENCHMARK_RESOURCE_DIR . $benchmark_identifier . "/post.sh " . BENCHMARK_ENV_DIR . "$benchmark_identifier");
	}

	// End
	if(!empty($result_scale))
	{
		$RETURN_STRING = "$benchmark_title:\n";
		$RETURN_STRING .= "$arguments_description\n";

		if(!empty($arguments_description))
			$RETURN_STRING .= "\n";

		$TOTAL_RESULT = 0;
		foreach($BENCHMARK_RESULTS_ARRAY as $result)
		{
			$TOTAL_RESULT += trim($result);
			$RETURN_STRING .= $result . " $result_scale\n";
		}

		$AVG_RESULT = pts_trim_double($TOTAL_RESULT / sizeof($BENCHMARK_RESULTS_ARRAY), 2);

		$RETURN_STRING .= "\nAverage: $AVG_RESULT $result_scale";

		echo pts_string_header($RETURN_STRING);
	}
	else
		$AVG_RESULT = -1;

	pts_process_remove($benchmark_identifier);
	return $AVG_RESULT;
}

?>
