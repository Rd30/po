<?php
/* this script manages the collection of data based on purchase order numbers */

// bring in dependencies
include_once "/var/www/lib/php/html/html_utils.php";
include_once "/var/www/lib/php/db/progress_connector.php";
include_once "/var/www/lib/php/fpdf/fpdf.php";

// import config files
$qad_config_data = parse_ini_file("/var/www/config/qad_connect.ini");
$it_config_data = parse_ini_file("/var/www/config/it_database.ini");

// setup Progress QAD environment variables
$dsn = "Progress";
putenv("ODBCINI=" . $qad_config_data["ODBCINI"]); 
putenv("ODBCINST=" . $qad_config_data["ODBCINST"]);
putenv("LD_LIBRARY_PATH=" . $qad_config_data["LD_LIBRARY_PATH"]);

// define constants
define("MODE_PREVIEW",  "prev");
define("MODE_EXPORT",   "exp");
define("MODE_EMAIL",    "email");
define("MODE_EMBED",    "embed");

define("TEXT_MARGIN", $text_margin1);
define("SUBTEXT_MARGIN", $text_margin2);
define("OFFSET_MARGIN", $text_margin6);
define("CRLF", $crlf);

define("PO_NOTE_LINE", "***** Please Acknowledge *****");

$currentDir = getcwd();
$uploadDirectory = "/uploads/";

foreach (array_keys($_FILES) as $myfile) {
  $errors = []; // Store all foreseen and unforseen errors here

  $fileName = $_FILES[$myfile]['name'];
  $fileSize = $_FILES[$myfile]['size'];
  $fileTmpName  = $_FILES[$myfile]['tmp_name'];
  $fileType = $_FILES[$myfile]['type'];
  $fileExtension = strtolower(end(explode('.',$fileName)));

  $uploadPath = $currentDir . $uploadDirectory . basename($fileName); 

  error_log($uploadPath);

  if (isset($fileName)) {

    if ($fileSize > 10000000) {
      $errors[] = "This file is more than 2MB. Sorry, it has to be less than or equal to 2MB";
    }

    if (empty($errors)) {
      $didUpload = move_uploaded_file($fileTmpName, $uploadPath);

      if ($didUpload) {
        //error_log("The file " . basename($fileName) . " has been uploaded");
      } else {
        error_log("An error occurred somewhere. Try again or contact the admin");
      }
    } else {
      foreach ($errors as $error) {
        error_log($error . "These are the errors" . "\n");
      }
    }
  }
}

// bring in http request arguments
$http_container = $_GET;

$po_number = null;
if (isset($_GET["po"])) {
  $po_number = $_GET["po"];
}
else if (isset($_POST["po"])) {
  $po_number = $_POST["po"];
  $http_container = $_POST;
}

$username = null;
if (isset($_GET["username"])) {
  $username = $_GET["username"];
}
else if (isset($_POST["username"])) {
  $username = $_POST["username"];
  $http_container = $_POST;
}

$mode = MODE_PREVIEW;
if (isset($_GET["pdf"])) {
  $mode = $_GET["pdf"];
}
else if (isset($_POST["pdf"])) {
  $mode = $_POST["pdf"];
}

$custom_emails = array();
if (isset($_POST["email"]) && $_POST["email"]) {
  if ($_POST["email"] != "") {
    $custom_emails = multiexplode(array(",", ";", ", ", "; ", "|", "| "), $_POST["email"]);
  }
}
$embed_po = false;
if (isset($_POST["embed_po"]) && $_POST["embed_po"] === "true") {
  $embed_po = true;
}

if ($username == null || $username == "") {
  echo "username error";
  exit(0);
}

// setup pdf 
class PDF extends FPDF {
  
  public function getWidth() {
    return $this->w;
  }
}

// attempt to open a connection with QAD
if ($conn_id = odbc_connect("Progress", "mfg", "", SQL_CUR_USE_ODBC)) {
  
  $data = array();
    
  // Get po_mstr (purchase order master) based on po number 
  $data = array_merge($data, queryPoMstr($conn_id, $po_number));
  
  // get pod_det (purchase order detail) based on po number
  $data = array_merge($data, queryPoDet($conn_id, $po_number)); 
    
  // get ad_mstr (address master) based on po vendor code
  $data = array_merge($data, queryAdMstr($conn_id, $data["po_vend"]));
  
  // OPTIONAL: get cd_det (comment detail) based on po vendor code
  $data = array_merge($data, queryCdDet($conn_id, $data["po_vend"]));

  // OPTIONAL: get ct_mstr (credit terms master) based on po credit terms
  $data = array_merge($data, queryCtMstr($conn_id, $data["po_cr_terms"]));  
  	  
  $userdata = getUserData($username);
   
  
  if ($userdata == null || count($userdata) < 1) {
    echo "username error";
    odbc_close($conn_id);
    exit(0);
  }
    
  $detail_array = generateHTML($conn_id, $data, $userdata);
  
  if ($mode === MODE_PREVIEW) {
    // Run the data through the html generator
    
    $gen_email = getGeneratedEmail($data, $userdata, $crlf);    
    
    $data_array = array(
      "html" => sanitizeStrForJSON($detail_array["html"]),
      "email" => sanitizeStrForJSON($gen_email),
      "drawings" => $detail_array["drawings"],
      "drawing_errors" => $detail_array["drawing_errors"]
    );
  
    echo json_encode($data_array);
  }
  else if ($mode === MODE_EMBED) {
    $email_text = generatePlainText($conn_id, $data, $userdata, $detail_array);
    generateEmail($detail_array["drawings"], $userdata, $detail_array["emails"], $email_text);
  }
  else {
    // run the data through the pdf generator
    $detail_array = generatePDF($conn_id, $data, $userdata);    
  }
  
  odbc_close($conn_id);  
}
else {
  
  error_log("cannot execute '$sql' ");
  error_log(odbc_errormsg());
  
}

/*****

Functions

******/

function serializePOData($html, $email) {
  
  $ret_data = "{" .
    "\"html\": \"" . $html . "\", " .
    "\"email\": \"" . $email . "\"}";    
  
  return $ret_data;
}

function sendPOQuery($conn_id, $sql, $optional=false) {
  $ret_data = array();
  if ($qad_response=odbc_exec($conn_id, $sql)) {
    $qad_result = parseProgressResult($qad_response);
    if (isset($qad_result[0])) {
      $ret_data = $qad_result[0];
    }
  }
  else if (!$optional) {
    echo $qad_config_data["po_error_msg"];
    exit();
  }
  
  if ($ret_data == null) {
    return array();
  }
  else {
    return $ret_data;
  }
}

function queryPoMstr($conn_id, $po_number) {
  $sql = "" .
    "SELECT * " .
      "FROM PUB.po_mstr " .
      "WHERE LOWER(po_nbr) = LOWER('" . $po_number . "')";      
  
  $ret_data = sendPOQuery($conn_id, $sql);
  
  //echo "queryPoMstr error";
  
  if (!array_key_exists("po_vend", $ret_data)) {
    echo "po error";
    exit();
  }
  
  return $ret_data;
}

function queryCtMstr($conn_id, $po_cr_terms){
	$sql = "" .
    "SELECT ct_desc " .
      "FROM PUB.ct_mstr " .
	  "WHERE LOWER(ct_code) = LOWER('" . $po_cr_terms . "')";      
  
  $ret_data = sendPOQuery($conn_id, $sql);
  
  if (count($ret_data) < 1) {
    echo "Cr_Terms is not linked to a purchase order details entry";
    exit();
  }
  
  return $ret_data;
}

function queryPoDet($conn_id, $po_number) {
  $sql = "" .
    "SELECT * " .
      "FROM PUB.pod_det " .
      "WHERE LOWER(pod_nbr) = LOWER('" . $po_number . "')";
  
  $ret_data = sendPOQuery($conn_id, $sql);
  
  if (count($ret_data) < 1) {
    echo "PO number is not linked to a purchase order details entry";
    exit();
  }
  
  return $ret_data;
}

function queryAdMstr($conn_id, $po_vend) {
  $sql = "" .
    "SELECT * " .
      "FROM PUB.ad_mstr " .
      "WHERE LOWER(ad_addr) = LOWER('" . $po_vend . "')";
      
  $ret_data = sendPOQuery($conn_id, $sql);
  
  if (count($ret_data) < 1) {
    echo "PO is not linked to a vendor";
    exit();
  }
  
  return $ret_data;
}

function queryCdDet($conn_id, $po_vend) {
  $sql = "" .
    "SELECT cd_cmmt " .
      "FROM PUB.cd_det " .
      "WHERE LOWER(cd_ref) = LOWER('" . $po_vend . "')";
  
  $cd_data = array();
  
  if ($qad_response=odbc_exec($conn_id, $sql)) {
    $cd_data = parseProgressResult($qad_response);
  }
  
  $cd_cmmt = "";
  foreach ($cd_data as $cd) {
    $cd_cmmt .= $cd["cd_cmmt"];
  }
  
  $ret_data = array(
    "cd_cmmt" => $cd_cmmt
  );  
  
  //return $ret_data;
  return $ret_data;
}

function queryTaxDetail($conn_id, $po_number, $totAmt) {
  $sql = "" .
    "SELECT tx2d_cur_tax_amt " .
      "FROM PUB.tx2d_det " .
	  "WHERE ((LOWER(tx2d_nbr) = LOWER('" . $po_number . "')) OR ( LOWER(tx2d_ref) = LOWER('" . $po_number . "'))) AND (tx2d_totamt = ". $totAmt . ")";
	  
	  
   $tax_data = array();
  
   if ($qad_response=odbc_exec($conn_id, $sql)) {
	$tax_data = parseProgressResult($qad_response);
   }
  
  $tax_amt = "";
  foreach ($tax_data as $td) {	  
    $tax_amt .= $td["tx2d_cur_tax_amt"];	
  }
  
  $ret_data = array(    
	"tx2d_cur_tax_amt" => number_format($tax_amt, 2, '.', '')
  ); 
  
  
  if (count($ret_data) < 1) {
    echo "queryTaxDetail error";
    exit();
  }
  
  return $ret_data;  
}

function processEmails($data, $email_regex) {
  // check fax  
  $fax_emails = array();
  preg_match_all($email_regex, $data["ad_fax"], $fax_emails);
  // check attn
  $attn_emails = array();
  preg_match_all($email_regex, $data["ad_attn2"], $attn_emails);  
  
  $cd_emails = array();
  preg_match_all($email_regex, $data["cd_cmmt"], $cd_emails);
  
  $fax_emails = $fax_emails[0];
  $attn_emails = $attn_emails[0];
  $cd_emails = $cd_emails[0];
  
  // compile a list and reduce duplicates
  
  $emails = array();
  $emails = array_merge($fax_emails, $emails);
  $emails = array_merge($attn_emails, $emails);
  $emails = array_merge($cd_emails, $emails);
  $emails = array_iunique($emails); 
  
  return $emails;
}


function generateHTML($conn_id, $data, $userdata) {
  global $email_regex;
  global $po_number;
  
  // get img
  $logo_path = "entegris.jpg";
  $logo_type = pathinfo($logo_path, PATHINFO_EXTENSION);
  $logo_data = file_get_contents($logo_path);
  $logo_base64 = "data:image/" . $logo_type . ";base64," . base64_encode($logo_data);
  $html = "" .
    "<div id='po_container' style='border:1px solid black; padding:25px 50px 50px 50px'>" .
      "<div style='display: table; width: 100%;'>" .
        "<div id='content'>" .
        "<table style='width: 100%;'>" .
          "<tr>" .
            "<td width='128'>" .
              "<img src='" . $logo_base64 . "'>" . 			  
            "</td>" .           
            "<td width='200'><strong>" .
              "Entegris, Inc.<br>" .
              "4175 Santa Fe Rd.<br>" .
              "San Luis Obispo, CA 93401<br>" .
              "UNITED STATES " .
            "</strong></td>" .            
            "<td style='vertical-align: top; text-align: right; height: 100%; padding: 10px 20px 0px 20px;'>" .
              "<div style='display: inline-flex;'>" .
                "<h5>Purchase Order: <strong>" . $data["po_nbr"] . "</strong></h5>" .				
              "</div>" .
			  "<div style='padding: 0px 0px 8px 0px;'>".
				"Revision: " .number_format($data["po_rev"], 1, '.', '') . 
			  "</div>".				
			  "<div>".
				$userdata[0]["User"] . "<br>" .
				(new \DateTime())->format('m/d/Y') .
			  "</div>" .
            "</td>" .
          "</tr>" .
        "</table>" .
        "</div>" .
      "</div><br><br>" .
      "<table>" .
        "<tr>" .          
          "<td>" .
            "<p style='color: rgb(0, 0, 99); font-size: 12pt; transform: translate(40px) rotate(270deg) translate(-4px); transform-origin: left bottom 0;'>" .
              "<strong><u>Vendor</u></strong>" .
            "</p>" .
          "</td>" .
          "<td style='text-align: top; height: 100%; margin-top: 0px; padding-top: 0px;'>" .
            "<p style='font-size: 10pt;color: rgb(0, 0, 0);'><strong>" .
              $data["po_vend"] . "<br>" .
              $data["ad_name"] . "<br>" .
              $data["ad_line1"] . " " . $data["ad_line2"] . " " . $data["ad_line3"] . "<br>" .
              $data["ad_city"] . ", " . $data["ad_state"] . " " . $data["ad_zip"] . "<br>" .
              $data["ad_country"] . 
            "</strong></p>" .
          "</td>" .
          "<td style='width: 200px;'>&nbsp;</td>" .
          "<td>" .
            "<p style='color: rgb(0, 0, 99); font-size: 12pt; transform: translate(40px) rotate(270deg) translate(-6px); transform-origin: left bottom 0;'>" .
              "<strong><u>Ship To</u></strong>" .
            "</p>" .
          "</td>" .
          "<td style='text-align: top; height: 100%'>" .
            "<p style='color: rgb(0, 0, 0);font-size: 10pt;'><strong>" .
              "Entegris, Inc.<br>" .
              "4175 Santa Fe Rd.<br>" .
              "San Luis Obispo, CA 93401<br>" .
              "UNITED STATES " .
            "</strong></p>" .
          "</td>" .
          "<td style='width: 20%;'>&nbsp;</td>" .
        "</tr>" .
      "</table>" .
      "<br>";
  
  $html .= "" .
      "<table>" .
        "<tr><h5 style='color: rgb(0, 0, 99);'>Contact Info</h5></tr>" .
        "<tr><td style='width:145px;'><strong>Vendor:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["ad_name"] . "</td></tr>" .
        "<tr><td><strong>Supplier Contact:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["po_contact"] . "</td></tr>" .
        "<tr><td><strong>Supplier Phone:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["ad_phone"] . "</td></tr>" .
        "<tr><td><strong>Ship Via:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["po_shipvia"] . "</td></tr>" .
        "<tr><td><strong>FOB Point:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["po_fob"] . "</td></tr>" .
        "<tr><td><strong>Payment Terms:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["ct_desc"] . "</td></tr>" .
        "<tr><td><strong>Email (s):</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;";

  //echo $html;
  
  $emails = processEmails($data, $email_regex);
  
  foreach ($emails as $email) {
    if ($email != null && $email != "") {
      $html .= $email . ";&nbsp;";
    }
  }
  
  /* Checking if the remarks is present 
  if(empty($data["po_rmks"]){
    echo 'NO REMARKS FOR THIS RECORD';
  }
  */
  
  /* do the header info */
  
  $html .= "</td></tr><tr><td>&nbsp;</td></tr>" .
        "<tr align='left'><td><strong>Buyer:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $userdata[0]["User"] . "</td></tr>" .
        "<tr align='left'><td align='left'><strong>Buyer Phone:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;1-805-786-" . $userdata[0]["Phone"] . "</td></tr>" .
        "<tr align='left'><td align='left'><strong>Buyer Email:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $userdata[0]["Email"] . "</td></tr></table><br>" .
		"<tr style='color: rgb(0, 0, 0);'><strong>&nbsp;ALL INVOICES MUST BE EMAILED TO: SPG_AP@entegris.com<br>" .
		"&nbsp;Entegris Purchase Order number must be referenced on invoice</strong></tr><br><br>" .
		"<tr align='left'><td><strong>&nbsp;Remarks:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;" . $data["po_rmks"] . "</td></tr><br><br>" .	
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>Notes:</strong></td><td>&nbsp;&nbsp;&nbsp;&nbsp;</td></tr><br>" .
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>Please provide written (email) response within 48 hours of receipt of this order, confirming material, <br></strong></td></tr>" .
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>pricing, and delivery date(s). <br><br></strong></td></tr>" .
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>PO number must appear on the outside of all containers as well as Entegris' part number and/or <br></strong></td></tr>".
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>description must be itemized on the packing slip.<br><br></strong></td></tr>".
		"<tr align='left'><td>&nbsp;</td></tr><tr><td align='left'><strong>Provide HTS code and Country of Origin (if applicable)</strong></td></tr>";
      
  
  $html  .= "" .
    "<hr style='border-color: black'>" .
    "<div class='comment_section'><table><tr><td>" .
      "<h3 style='color: rgb(0, 0, 99);'>Order Details</h3>";
  
  /* do conditions section */
  $html .= doConditionsStatement();
  
  /* do the master reference section */
  //$html .= doMasterRef($conn_id);
  
  /* do the detail comment section */
  $html .= doCommentDetail($data["po_cmtindx"], $conn_id);
  
  /* do details section */
  $detail_array = doDetail($conn_id, $po_number);
  
  $html .= $detail_array["detail"];
  $html .= "<strong>Line Total: $ ".number_format($detail_array["lineTotal"], 2, '.', ''). "</strong><br>";
  $html .= "<strong>Total Tax:  $ ".number_format($detail_array["totalTax"], 2, '.', ''). "</strong><br>";
  $html .= "<strong>Total:      $ ".number_format($detail_array["total"], 2, '.', ''). "</strong><br>";
    
  //$data["drawings"] = $detail_array["drawings"];
  //error_log(json_encode($data["drawings"]));
  
  $html .= "" .
        "</td></tr></table>" .
      "</div>" .
    "</div>";
  
  $detail_array["html"] = $html;
  $detail_array["emails"] = $emails;
    
  return $detail_array;
}

function generatePDF($conn_id, $data, $userdata) {
  global $mode;
  global $email_regex;
  global $po_number;
  
  // set up pdf configuration
  $pdf_config = parse_ini_file("po_pdf.ini");
  $x_cursor = $pdf_config["x_margin"];
  $y_cursor = 30;
  $font_10_y = $pdf_config["small_font"];
  $font_16_y = $pdf_config["regular_font"];
  $section_heading = $pdf_config["section_heading_font"];
  
  $pdf = new PDF();
  $pdf->SetAuthor($userdata[0]["User"]);
  $pdf->AddPage();

  $pdf->Image('entegris.jpg', 3, 10, $pdf->getWidth() - 180);   
  $pdf->Image('po_addr.jpg', 7, 32);
  
  $pdf->SetFont('Arial', '', 10); 
  //$pdf->SetXY(74, 16);
  $pdf->SetXY(30, 10); 
  $pdf->Cell(0, $font_10_y, "Entegris, Inc");
  $pdf->SetXY(30, 15);
  $pdf->Cell(0, $font_10_y, "4175 Santa Fe Rd.");
  $pdf->SetXY(30, 20);
  $pdf->Cell(0, $font_10_y, "San Luis Obispo, CA 93401");
  $pdf->SetXY(30, 25);
  $pdf->Cell(0, $font_10_y, "UNITED STATES");
  $pdf->SetXY(125, 10);
  $pdf->SetFont('Arial', '', 18);
  $pdf->Cell(0, $font_16_y, "Purchase Order:");
  $pdf->SetFont('Arial', '', 10);
  $pdf->SetXY(125, 15);
  $pdf->Cell(0, $font_16_y, "Revision:"); 	
  $pdf->SetXY(125, 22);
  $pdf->Cell(0, $font_10_y, $userdata[0]["User"]);
  $pdf->SetXY(125, 26);
  $pdf->Cell(0, $font_10_y, (new \DateTime())->format('m/d/Y'));
  $pdf->SetXY(175, 10);
  $pdf->SetFont('Arial', 'B', 18);
  $pdf->Cell(0, $font_16_y, $po_number);
  $pdf->SetXY(142, 15);  
  $pdf->SetFont('Arial', '', 10);
  $pdf->Cell(0, $font_16_y, number_format($data["po_rev"], 1, '.', ''));
  
  $pdf->Line(5, $y_cursor + 0.5, $pdf->getWidth() - 5, $y_cursor + 0.5);
  
  $pdf->SetFont('Arial', '', 10);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, $data["po_vend"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, $data["ad_name"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, $data["ad_line1"] . " " . $data["ad_line2"] . " " . $data["ad_line3"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, $data["ad_city"] . ", " . $data["ad_state"] . " " . $data["ad_zip"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, $data["ad_country"]);
  $x_cursor = 125;
  $y_cursor = 30;
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y,"Entegris, Inc.");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "4175 Santa Fe Rd.");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "San Luis Obispo, CA 93401");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "UNITED STATES");


  $pdf->Line(5, $y_cursor + 12, $pdf->getWidth() - 5, $y_cursor + 12);

  $x_cursor = $pdf_config["x_margin"];
  $y_cursor += 15;

  /* Contact Info Heading */
  $pdf->SetXY($x_cursor, $y_cursor);
  $pdf->SetTextColor(0, 0, 99);
  $pdf->SetFont('Arial', '', 16);
  $pdf->Cell(0, $font_16_y, "Contact Info"); 
  $pdf->SetTextColor(0, 0, 0);           
  /* Contact Info */
  $pdf->SetXY($x_cursor, $y_cursor += $font_16_y + 5);
  $pdf->SetFont('Arial', '', 10);
  $pdf->Cell(0, $font_10_y, "Vendor: " . $data["ad_name"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Supplier Contact: " . $data["po_contact"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Supplier Phone: " . $data["ad_phone"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Ship Via: " . $data["po_shipvia"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "FOB Point: " . $data["po_fob"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Payment Terms: " . $data["ct_desc"]);
  
  /*
  SUBTEXT_MARGIN . "Ship Via: " . $data["po_shipvia"] . CRLF .
          SUBTEXT_MARGIN . "FOB Point: " . $data["po_fob"] . CRLF .
          SUBTEXT_MARGIN . "Payment Terms: " . $data["po_cr_terms"] . CRLF .
          */

  $emails = array();
  $emails = get_strings_between($data["cd_cmmt"], "<", ">");
  $emails = array_merge($emails, explode(";", $data["cd_cmmt"]));

  // *** DEBUG STUFF ***
  //$emails = get_strings_between("James_Chiu@saes-group.com;Thomas_Elder@saes-group.com;\nPatrick Noble    <pnob32@gmail.com>", "<", ">");
  //$emails = array_merge($emails, explode(";", "James_Chiu@saes-group.com;Thomas_Elder@saes-group.com;Patrick_Noble@saes-group.com;\nPatrick Noble    <pnob32@gmail.com>"));
  
  $emails = processEmails($data, $email_regex);
  
  $email_str = "";
  for ($i = 0; $i < count($emails); $i++) {
    if (filter_var($emails[$i], FILTER_VALIDATE_EMAIL)) {
      $email = $emails[$i];
      if ($email != null && $email != "") {
        $email_str .= $email . "; ";
      }
    }
    else {
      array_splice($emails, $i, 1);
    }
  }

  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Email (s): " . $email_str);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, " ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Buyer: " . $userdata[0]["User"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Buyer Phone: 1-805-786-" . $userdata[0]["Phone"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Buyer Email: " . $userdata[0]["Email"]);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, " ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->SetFont('Arial', 'B', 10);
  $pdf->Cell(0, $font_10_y, "ALL INVOICES MUST BE EMAILED TO: SPG_AP@entegris.com");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Entegris Purchase Order number must be referenced on invoice");
  $pdf->SetFont('Arial', '', 10);
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, " ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y); 
  $pdf->Cell(0, $font_10_y, "Remarks: " . $data["po_rmks"]); /* adding remarks info*/
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, " ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "Notes: ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_16_y);
  $pdf->Cell(0, $font_10_y, "Please provide written (email) response within 48 hours of receipt of this order, confirming material,");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "pricing, and delivery date(s).");
  $pdf->SetXY($x_cursor, $y_cursor += $font_16_y);
  $pdf->Cell(0, $font_10_y, "PO number must appear on the outside of all containers as well as Entegris' part number and/or ");
  $pdf->SetXY($x_cursor, $y_cursor += $font_10_y);
  $pdf->Cell(0, $font_10_y, "description must be itemized on the packing slip.");
  $pdf->SetXY($x_cursor, $y_cursor += $font_16_y);
  $pdf->Cell(0, $font_10_y, "Provide HTS code and Country of Origin (if applicable)");

  $y_cursor += 10;
  $pdf->Line(5, $y_cursor, $pdf->getWidth() - 5, $y_cursor);
  $pdf->Ln(10);

  $y_cursor += 10;
  $pdf->SetXY($x_cursor, $y_cursor);
  $pdf->SetTextColor(0, 0, 99);
  $pdf->SetFont('Arial', '', 16);
  $pdf->Cell(0, $font_16_y, "Order Details"); 
  $pdf->SetTextColor(0, 0, 0);        

  $detailArray = doDetail($conn_id, $po_number);
  $detail = $detailArray["detail"];
  $lineTotal = $detailArray["lineTotal"];
  $totalTax = $detailArray["totalTax"];
  $total = $detailArray["total"];  
  $drawings = $detailArray["drawings"];
  
  
  $y_cursor += 10;	
  $pdf->SetXY($x_cursor, $y_cursor);
  $pdf->SetFont('Arial', '', 10);  
  $pdf->MultiCell(0, $font_10_y, doConditionsStatement() .
    //doMasterRef($conn_id) .
    doCommentDetail($data["po_cmtindx"], $conn_id) .
    $detail
  );
  
  $y_cursor = $pdf->getY() + 5;
  
  $pdf->SetXY($x_cursor, $y_cursor);    
  $pdf->SetFont('Arial', '', 12);
  $pdf->Cell(0, $font_10_y, "Line Total: $ ". number_format($lineTotal, 2, '.', ''));
  $y_cursor = $pdf->getY() + 5;	
  $pdf->SetXY($x_cursor, $y_cursor);
  $pdf->Cell(0, $font_10_y, "Total Tax: $ ". number_format($totalTax, 2, '.', ''));
  $y_cursor = $pdf->getY() + 5;	
  $pdf->SetXY($x_cursor, $y_cursor);
  $pdf->Cell(0, $font_10_y, "Total: $ ". number_format($total, 2, '.', ''));
  
  
  if ($mode === MODE_EMAIL) {
    $filename = "/var/www/tmpdocs/" . $po_number . ".pdf";
    $pdf->Output("F", $filename); 
    generateEmail($drawings, $userdata, $emails);
    unlink($filename);
  }
  else {
    $pdf->Output("I");
  }
}

function generatePlainText($conn_id, $data, $userdata, $detailArray) {
  global $po_number;
  
  $detailArray = doDetail($conn_id, $po_number);
  $detail = $detailArray["detail"];
  $drawings = $detailArray["drawings"];
  
  $text = "" .
        TEXT_MARGIN . PO_NOTE_LINE . CRLF .
        CRLF .
                OFFSET_MARGIN . "Vendor: " . $data["po_vend"] . " - " . $data["ad_name"] . CRLF .
                OFFSET_MARGIN . "PO Number: " . $po_number . CRLF .
                OFFSET_MARGIN . "Order Date: " . $data["po_ord_date"] . CRLF .
                CRLF .
        TEXT_MARGIN . "Ship To:" . CRLF .
          SUBTEXT_MARGIN . "Entegris, Inc." . CRLF .
          SUBTEXT_MARGIN . "4175 Santa Fe Rd." . CRLF .
          SUBTEXT_MARGIN . "San Luis Obispo, CA 93401" . CRLF .
          SUBTEXT_MARGIN . "UNITED STATES" . CRLF .
          CRLF .
          SUBTEXT_MARGIN . "Buyer: " . $userdata[0]["User"] . CRLF .
          SUBTEXT_MARGIN . "Buyer Phone: 1-805-786-" . $userdata[0]["Phone"] . CRLF .
          SUBTEXT_MARGIN . "Buyer Email: " . $userdata[0]["Email"] . CRLF .
          CRLF .
        TEXT_MARGIN . "Supplier: " . CRLF .
          SUBTEXT_MARGIN . "Contact: " . $data["po_contact"] . CRLF .
          SUBTEXT_MARGIN . "Phone: " . $data["ad_phone"] . CRLF .
          SUBTEXT_MARGIN . "Ship Via: " . $data["po_shipvia"] . CRLF .
          SUBTEXT_MARGIN . "FOB Point: " . $data["po_fob"] . CRLF .
          SUBTEXT_MARGIN . "Payment Terms: " . $data["ct_desc"] . CRLF .
          CRLF .
        doConditionsStatement() . 
        //doCommentDetail($data["po_cmtindx"], $conn_id) .
        $detail;
    
  
  return $text;
}

function generateEmail($drawings, $user_data, $emails, $text = null) {
  require_once "/var/www/lib/php/PHPMailer/PHPMailerAutoload.php";  
  //$mailer_config = parse_ini_file("/var/www/config/mail.ini");

  global $drawing_path;
  global $tmpdocs_path;
  global $custom_emails;
  global $mode;
  global $http_container;
  global $po_number;
  
  if ($custom_emails != null && count($custom_emails) > 0) {
    $emails = array();
    //$mail->addAddress($custom_email);
    foreach ($custom_emails as $custom_email) {
      array_push($emails, $custom_email);
    }
  }
  
  $mail = new PHPMailer;
  $mail->isSMTP();
  $mail->Host = "relay-west.entegris.com"; 
  $mail->SMTPOptions= array(
		'ssl'=> array(
			'verify_peer'=>false,
			'verify_peer_name'=>false,
			'allow_self_signed'=>true
		)
  );
  $mail->SMTPSecure = "tls";
  $mail->Port = 25;
  $mail->setFrom('donotreply@entegris.com', 'Entegris SLO Purchase Orders');
  $mail->addCC($user_data[0]["Email"]);
    
  foreach ($emails as $email) {
    if ($email != null && $email != "") { 
      $mail->addAddress($email);
    }
  }   
  
  // Set email format to HTML
  $mail->isHTML(true);
  
  $email_body = $http_container["email_body"];
  if ($text != null) {
    $mail->isHTML(false);
    $email_body = str_replace("<po_export>", $text, $email_body);
    $mail->Body = $email_body .
      "\n\n" .
        "CONFIDENTIALITY AND PRIVACY NOTICE\n" .
        "Information transmitted by this email is proprietary to Entegris, Inc and is intended for the use only by the individual or entity to which addressed, and may contain information that is privileged, confidential or exempt from disclosure under applicable law.  Any use, copying, retention or disclosure by any person other than the intended recipient's designees is strictly prohibited.  If you are not the intended recipient or their designee, please notify the sender immediately by return e-mail and delete all copies.\n";
  }
  else {
    $mail->Body = "" .
      str_replace("\n", "<br>", $email_body) .    
      "<hr>" .
      "<p>CONFIDENTIALITY AND PRIVACY NOTICE</p>" .
      "<p>Information transmitted by this email is proprietary to Entegris, Inc and is intended for the use only by the individual or entity to which addressed, and may contain information that is privileged, confidential or exempt from disclosure under applicable law.  Any use, copying, retention or disclosure by any person other than the intended recipient's designees is strictly prohibited.  If you are not the intended recipient or their designee, please notify the sender immediately by return e-mail and delete all copies.</p>"; 
    
    $mail->AltBody = $email_body .
      "\n\n" .
        "CONFIDENTIALITY AND PRIVACY NOTICE\n" .
        "Information transmitted by this email is proprietary to Entegris, Inc and is intended for the use only by the individual or entity to which addressed, and may contain information that is privileged, confidential or exempt from disclosure under applicable law.  Any use, copying, retention or disclosure by any person other than the intended recipient's designees is strictly prohibited.  If you are not the intended recipient or their designee, please notify the sender immediately by return e-mail and delete all copies.\n"; 
  }
  
  $mail->Subject = "Entegris - SLO, Purchase Order: " . $po_number;
  if ($mode !== MODE_EMBED) {
    $mail->addAttachment("/var/www/tmpdocs/" . $po_number . ".pdf");
  }
  
  require_once "/var/www/lib/php/zip/PHPZip.php";
  
  // find files, copy to tmpdocs and rename to <part number>.pdf, and zip it.
  // then delete the files
  $zip_files = array();
  $zip_path = $tmpdocs_path . $po_number . "_drawings.zip";
  foreach ($drawings as $drawing) {
    $file = $drawing_path . $drawing;
    $tmpfile = $tmpdocs_path . str_replace("/", "", $drawing);
    
    if (!copy($file, $tmpfile)) {
      error_log("could not copy " . $file . " to " . $tmpfile);
    }
    $tmpfile = $tmpdocs_path . str_replace("/", "", $drawing);
    //$zip_files[] = $tmpfile;
    $mail->addAttachment($file, str_replace("/", "", $drawing));
    //$mail->Body = sprintf($app_config_data["EMAIL_REQUEST"], $absenceInfo[$app_config_data["REQUESTED_BY"]], $app_config_data["MANAGEMENT_URL"], $request_id);
    //$mail->AltBody = sprintf($app_config_data["ALT_EMAIL_REQUEST"], $absenceInfo[$app_config_data["REQUESTED_BY"]], $app_config_data["MANAGEMENT_URL"], $request_id);
  }
  
  // handle attachments...
  foreach (array_keys($_FILES) as $myfile) {
    error_log("attaching " . "/var/www/html/mat/po/uploads/" . $_FILES[$myfile]["name"]);
    $mail->addAttachment("/var/www/html/mat/po/uploads/" . $_FILES[$myfile]["name"]);
  }
  
  if (!$mail->send()) {    
    error_log('PO E-mail Error: ' . $mail->ErrorInfo);
  }
  else {    
    echo "success";
  }
  
  // destroy temp attachments
  foreach (array_keys($_FILES) as $myfile) {
    unlink("/var/www/html/mat/po/uploads/" . $_FILES[$myfile]["name"]);
  }
  
  foreach ($zip_files as $zipfile) {
    unlink($zipfile);
  }
}

function getUserData($username) {
  $it_config_data = parse_ini_file("/var/www/config/it_database.ini");
  
  $userdata = null;
  
  $conn = new mysqli($it_config_data["m_host"], $it_config_data["m_user"], $it_config_data["m_password"], $it_config_data["m_database"]);
  if ($conn->connect_errno) {
    error_log("Failed to connect to MySQL: (" . $conn->connect_errno . ") " . $conn->connect_error);
  }
  else {
    $it_sql = "" .
      "SELECT * " .
        "FROM `names` " .
        "WHERE `User` LIKE '" . $username . "'";
  
    $userdata = parseQueryResponse(sendQuery($conn, $it_sql));     
    
    mysqli_close($conn);
  }
  return $userdata;
}

function doConditionsStatement() {
  global $mode;
  if ($mode === MODE_PREVIEW) {
    return "" .
        "<p style='color: rgb(0, 0, 0);'>" .
          "Acceptance of this purchase order indicates your agreement to Entegris' standard terms and conditions, <br>" .
          "which have been provided under separate cover and can be found at<br><br>" .
          "<a href='http://www.entegris.com/Resources/images/13920.pdf' target='_blank'>http://www.entegris.com/Resources/images/13920.pdf</a>" .
        "</p><p style='color: rgb(0, 0, 0);'>" .
          "Shipping instructions: All deliveries of hazardous materials shall include a current Safety Data Sheet <br><br>" .
          "Use the following accounts for shipping ground (unless otherwise noted). <br><br>" .
		  "UPS: E3888R    FedEx: 1005-8364-0    FedEx Freight: 931194992" .
        "</p><br><br>";
  }
  else if ($mode === MODE_EMBED) {
    return "" .
        TEXT_MARGIN . "Acceptance of this purchase order indicates your agreement to Entegris' standard terms and conditions, " . CRLF . 
        TEXT_MARGIN . "which have been provided under separate cover and can be found at" . CRLF . 
        TEXT_MARGIN . "http://www.entegris.com/Resources/images/13920.pdf" . CRLF . CRLF . 
        TEXT_MARGIN . "Shipping instructions: All deliveries of hazardous materials shall include a current Safety Data Sheet" . CRLF . 
        TEXT_MARGIN . "Use the following accounts for shipping ground (unless otherwise noted)." . CRLF .
		TEXT_MARGIN . "UPS: E3888R    FedEx: 1005-8364-0    FedEx Freight: 931194992" . CRLF . CRLF;

  }
  else {
    return "" .
        "Acceptance of this purchase order indicates your agreement to Entegris' standard terms and conditions,\n" .
        "which have been provided under separate cover and can be found at\n\n" .
        "http://www.entegris.com/Resources/images/13920.pdf\n\n" .
        "Shipping instructions: All deliveries of hazardous materials shall include a current Safety Data Sheet\n\n" .
        "Use the following accounts for shipping ground (unless otherwise noted).\n\n" .
		"UPS: E3888R    FedEx: 1005-8364-0    FedEx Freight: 931194992\n\n" ;

  }
}

function doMasterRef($conn_id) {
  $ref_sql = "" .
    "SELECT * " .
      "FROM PUB.cd_det " .
      "WHERE " .
        "cd_domain = 'SPG' " .
        "AND cd_ref = 'Receipt Window'";
  
  if ($ref_result=odbc_exec($conn_id, $ref_sql)) {
    $ref_data = parseProgressResult($ref_result);
    
    if ($ref_data != null && count($ref_data) > 0) {
      $text = str_replace(";", "<br>", $ref_data[0]["cd_cmmt"]);
      $text = str_replace("<br><br>", "", $text);
      /*$text = str_replace("Do not ship material on this Purchase Order to arrive more than five 5<br>" .
        "working days prior to the Due Date listed. If material arrives sooner, it<br>" .
        "will be held in SAES Receiving area until five 5 days prior to the Due<br>" .
        "Date.", "", $text);*/
      return "<p style='color: rgb(0, 0, 0);'>" . $text . "</p>";
    }
    else {
      return "<p style='color: rgb(0, 0, 0);'>Error getting master reference data</p>";
    }
  }
  else {
    error_log("cannot execute '$ref_sql' ");
    error_log(odbc_errormsg());
  }
}

function doCommentDetail($index, $conn_id) {
  global $mode;
  
  $cmt_sql = "" . 
    "SELECT * " .
      "FROM PUB.cmt_det " .
      "WHERE " .
        "cmt_domain = 'SPG' " .
        "AND cmt_indx = '" . $index . "' " .
        "AND cmt_print like '%PO%'";
        
  if ($cmt_result=odbc_exec($conn_id, $cmt_sql)) {
    $cmt_data = parseProgressResult($cmt_result);
    
    if ($cmt_data != null && count($cmt_data) > 0) {
      //error_log(json_encode($cmt_data));
      
      $cmt_det_str = "";
      
      foreach ($cmt_data as $cmt_entry) {
        if ($mode === MODE_PREVIEW) {
          // mode is html preview          
          $text = "<p style='color: rgb(0, 0, 0);'>";		  
          $text .= str_replace(";", "<br>", $cmt_entry["cmt_cmmt"]);
          $text = str_replace("<br>", "", $text);
          
          $cmt_det_str .= $text . "</p>";
        }
        else if ($mode === MODE_EMBED) {
          // mode is plain text embed
          $text = "" .
            CRLF . str_replace(";", CRLF, $cmt_entry["cmt_cmmt"]);
          
          $text = str_replace(CRLF, "", $text);
          
          $cmt_det_str .= CRLF . $text . CRLF;
        }
        else {
          // mode is pdf
          
          $text = CRLF;
          $text .= str_replace(";", "", $cmt_entry["cmt_cmmt"]);
          $text = str_replace(CRLF . CRLF, "", $text);          
          $cmt_det_str .= $text . CRLF;
        }
      }
      //error_log("cmt_det_str: " . $cmt_det_str);
      //$text = str_replace(CRLF, CRLF . TEXT_MARGIN, $text);
      $text = str_replace(CRLF . CRLF .CRLF .CRLF, "", $text);
	  
	  $cmt_det_str = str_replace("If shipping via UPS, use acct#E3888R. FedEx use acct#1005-8364-0", " ", $cmt_det_str);
	  $cmt_det_str = str_replace(".", "", $cmt_det_str);
	  
      return $cmt_det_str;
    }
    else {
      //return "<p>Error getting master reference data</p>";
      return "";
    }
  }
  else {
    error_log("cannot execute '$cmt_sql' ");
    error_log(odbc_errormsg());
  }
}

function doDetail($conn_id, $po_number) {
  global $drawing_path;
  global $mode;
  
  $spSmall = ($mode === MODE_PREVIEW ? "&nbsp;&nbsp;&nbsp;" : "   ");
  $spShort = ($mode === MODE_PREVIEW ? "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" : "          ");
  
  $det_sql = "" .
    "SELECT * " .
      "FROM PUB.pod_det " .
        "JOIN PUB.po_mstr ON po_domain = pod_domain AND po_nbr = pod_nbr " .
        "LEFT JOIN PUB.pt_mstr ON pod_domain = pt_domain AND pod_part = pt_part " .
      "WHERE " .
        "pod_domain = 'SPG' " .
        "AND pod_nbr = '" . $po_number . "' order by pod_line";
	 
  if ($det_result=odbc_exec($conn_id, $det_sql)) {
    $det_data = parseProgressResult($det_result);
    
    if ($det_data != null && count($det_data) > 0) {
      
      //error_log(json_encode($det_data));
      
      $det_str = ($mode === MODE_PREVIEW ? "" : "\n");
      
      $drawings = array();
      $drawing_errors = array();
      
      foreach ($det_data as $det_entry) {
        
        
        $qtyord = $det_entry["pod_qty_ord"];
        $purcost = $det_entry["pod_pur_cost"];
        $extcost = $qtyord * $purcost;
        $vend  = $det_entry["po_vend"];
        $desc1 = $det_entry["pt_desc1"];
        $desc2 = $det_entry["pt_desc2"];
        $vendpart = $det_entry["pod_vpart"];
        $cmmt = $det_entry["pod_desc"];
        $part = $det_entry["pt_part"];
        $drawing = $det_entry["pt_draw"];
        $rev = $det_entry["pt_rev"];
		$tax = $det_entry["pod_taxable"];
		
		
		$dueDateFormatted = date('m/d/Y',strtotime($det_entry["pod_due_date"]));
		
        
		$qtyord = round((float)($qtyord), 4);
        $purcost = round((float)$purcost, 3);
        $extcost = round((float)$extcost, 2);
		
		$totalTax;
		$lineTotal += $extcost;
		
		
		if($tax != null && $tax != "" && $tax == 1){
						
			$tax_amt = queryTaxDetail($conn_id, $po_number, $extcost);			
						
			foreach ($tax_amt as $td => $value) {				
				$tax_val = $value;
				$totalTax += $tax_val;
				$totalExtCost = $totalExtCost + $extcost + $tax_val;
			}
						
		}else{
			$totalExtCost += $extcost;
		}		
		
        
        if ($mode === MODE_PREVIEW) {
          // mode is html preview
          
          $det_str .= "<p class='part_desc_text' style='color: rgb(0, 0, 0);'>";
          
          $det_str .= $det_entry["pod_line"] . "." . $spSmall . $det_entry["part"] . $spSmall . " Due: " . $dueDateFormatted .
            $spSmall . $qtyord . "&nbsp;" . $det_entry["pod_um"] . "&nbsp;&nbsp;@&nbsp;&nbsp;$" . number_format($purcost, 2, '.', '') . " == $" . number_format($extcost, 2, '.', '') . "<br>";
        
          if ($desc1 != null) {
            $det_str .= $spShort . $desc1 . "&nbsp;" . $desc2 . "<br>";
          }
          
          if ($cmmt != null && $cmmt != "") {
            $det_str .= $spShort . "Cmmt:&nbsp;" . $cmmt . "<br>";
          }
          
          if ($vendpart != null && $vendpart != "" && strpos($vendpart, "'") == false) {
            $det_str .= $spShort . "Vendor Part:&nbsp;" . $vendpart . "<br>";
            
            $vp_sql = "select vp_mfgr, vp_mfgr_part from PUB.vp_mstr where vp_domain = 'SPG' and vp_vend = '" . $vend . "' and vp_vend_part = '" . $vendpart . "' and " .
                " vp_part = '" . $part . "'";
            
            if ($vp_result=odbc_exec($conn_id, $vp_sql)) {
              $vp_data = parseProgressResult($vp_result);
              if ($vp_data != null) {
                foreach ($vp_data as $vp_entry) {
                  $mfg = $vp_entry["vp_mfgr"];
                  $mfgPart = $vp_entry["vp_mfgr_part"];
                  
                  if ($mfg != null) {
                    $det_str .= $spShort . "MFG:&nbsp;" . $mfg;
                  }
                  if ($mfgPart != null) {
                    $det_str .= $spShort . "MFG Part:&nbsp;" . $mfgPart . "<br>";
                  }
                }
              }
            }			
          }
          if ($rev != null && $rev != "")
          {
            $det_str .= $spShort . "Rev:&nbsp;" . $rev ;
          }
		  
		  if ($tax != null && $tax != "" && $tax == 0)
          {
            $det_str .= $spShort . "Tax: N\n"  ;
          }
		  else{
			$det_str .= $spShort . "Tax: Y\n"  ;  
		  }
          
          $det_str .= doCommentDetail($det_entry["pod_cmtindx"], $conn_id) . "<br>";     
          
          $det_str .= "</p>";
        }
        else {
          // mode is pdf
          
          $det_str .= "\n";
          $det_str .= $det_entry["pod_line"] . "." . $spSmall . $det_entry["part"] . $spSmall . " Due: " . $dueDateFormatted . 
          $spSmall . round((float)($qtyord), 4) . " " . $det_entry["pod_um"] . "  @  $" . number_format($purcost, 2, '.', '') . " == $" . number_format($extcost, 2, '.', '') . "\n";
      
          if ($desc1 != null) {
            $det_str .= $spShort . $desc1 . " " . $desc2 . "\n";
          }
          
          if ($cmmt != null && $cmmt != "") {
            $det_str .= $spShort . "Cmmt: " . $cmmt . "\n";
          }
          //error_log("vendor part: " . $vendpart);
          if ($vendpart != null && $vendpart != "" && strpos($vendpart, "'") == false) {
            $det_str .= $spShort . "Vendor Part: " . $vendpart . "\n";
            
            //$vp_sql = "select vp_mfgr, vp_mfgr_part from PUB.vp_mstr where vp_domain = 'SPG' and vp_vend = '" . $vend . "' and vp_vend_part = '" . $vendpart . "' and " .
            //   " vp_part = '" . $part . "'";
            $vp_sql = "select * from PUB.vp_mstr where vp_domain = 'SPG' and vp_vend = '" . $vend . "' and vp_vend_part = '" . $vendpart . "' and " .
                " vp_part = '" . $part . "'";
            
            //error_log("vp_vend: " . $vend . ", vp_vend_part: " . $vendpart . ", vp_part: " . $part);
            
            if ($vp_result=odbc_exec($conn_id, $vp_sql)) {
              
              $vp_data = parseProgressResult($vp_result);
              if ($vp_data != null) {
                foreach ($vp_data as $vp_entry) {
                  $mfg = $vp_entry["vp_mfgr"];
                  $mfgPart = $vp_entry["vp_mfgr_part"];
                  
                  if ($mfg != null) {
                    $det_str .= $spShort . "MFG: " . $mfg;
                  }
                  if ($mfgPart != null) {
                    $det_str .= $spShort . "MFG Part: " . $mfgPart . "\n";
                  }
                }
              }
            }
            else {
              error_log("cannot execute '$vp_sql' ");
              error_log(odbc_errormsg());
            }
          }
          if ($rev != null && $rev != "")
          {
            $det_str .= $spShort . "Rev: " . $rev;
          }
		  
		  if ($tax != null && $tax != "" && $tax == 0)
          {
            $det_str .= $spShort . "Tax: N\n"  ;
          }
		  else{
			$det_str .= $spShort . "Tax: Y\n"  ;  
		  }
          
          $det_str .= doCommentDetail($det_entry["pod_cmtindx"], $conn_id) . "\n";     
          
         /*if ($mode === MODE_EMBED) {
            $det_str = str_replace(CRLF, CRLF . TEXT_MARGIN, $det_str);
          }*/
          
          $det_str .= CRLF;
        }
        
        // Handle Drawings
        if ($part != null && $drawing != null && $rev != null)
        {
          $part_str = (string)$part;
          
          $seg_1 = 2;
          if (!is_numeric($drawing)) {
            
          }
          
          if (!in_array(substr($part_str, 0, 2) . "/" .
                substr($part_str, 2, 3) . "/" .
                substr($part_str, 5, 2) . 
                "_" . $rev . ".pdf", $drawings)) {
            
            $file_str = substr($part_str, 0, 2) . "/" .
              substr($part_str, 2, 3) . "/" .
              substr($part_str, 5, 2) . 
              "_" . $rev . ".pdf";
              
            
            error_log("file string: " . $file_str);
			error_log("Unable to fetch the drawing :" .$drawing. " and Part number :" .$part_str);
            
            if (file_exists($drawing_path . $file_str)) {
              array_push($drawings, $file_str);
            }
            else {
              array_push($drawing_errors, array(
                "file_str" => $file_str,
                "part_nbr" => $part_str
              ));
            }
          }
        }
      }
      
      if ($mode === MODE_EMBED) {
        $det_str = str_replace(CRLF, CRLF . TEXT_MARGIN, $det_str);
      }
      
      return array(
        "detail" => $det_str,
		"lineTotal" => $lineTotal,		
		"totalTax" => $totalTax,
		"total" => $totalExtCost,
        "drawings" => $drawings,
        "drawing_errors" => $drawing_errors
      );
    }
    else {
      return "<p>Error getting master reference data</p>";
    }
  }
  else {
    error_log("cannot execute '$det_sql' ");
    error_log(odbc_errormsg());
  }
  
}

function getGeneratedEmail($data, $user_data, $crlf) {
  return "" .
    $data["ad_name"] . "," . $crlf . $crlf .
    "Please process the following Purchase Order " . $data["po_nbr"] . " attached and the required drawing(s) if applicable." . $crlf . $crlf .
    "Please let me know if you have any questions or concerns." . $crlf . $crlf .
    "Kind regards," . $crlf . $crlf .
    $user_data[0]["User"] . $crlf .
      $user_data[0]["Role"] . $crlf .
      "Entegris, Inc" . $crlf .
      "4175 Santa Fe Rd" . $crlf .
      "San Luis Obispo, CA 93401" . $crlf .
      "805-786-" . $user_data[0]["Phone"] . " - Office" . $crlf;
}