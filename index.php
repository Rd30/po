<!DOCTYPE html>
<html lang="en_us">
<head>   
  <?php include("http://nd-wind.entegris.com/gp-slo/common/gp-sloHead.html"); ?>  
  <style>    
    body { background:#FFFFFF url('http://nd-wind.entegris.com/gp-slo/img/minitar.gif') no-repeat fixed center 50%;}
  </style>  
</head>

<body>
	<!-- Dark overlay element -->
    <div class="overlay" id="overlay"></div>

    <!--NavBar/Header-->
    <div class="all-gp-sloHeader" id="poHeader">
      <?php include("http://nd-wind.entegris.com/gp-slo/common/gp-sloHeader.html"); ?>
    </div>

    <!--SideBar-->
    <?php include("http://nd-wind.entegris.com/gp-slo/common/gp-sloSidebar.html"); ?>	
    
  <!-- page content -->
  <div class="page-content">
    <div style="background-color: rgb(249, 249, 249); border-right: 2px solid rgb(231, 231, 231);" class="page-margin-left margin left-margin-border main">
    </div>
    <div class="page-body main" >
      
      <!-- Heres where the business goes -->
      <!-- Copy this portion into the ember app template ... don't forget to create a route! -->
    
    <div  class="container">
      <div id="po_request_container">
        <h5>Get a Purchase Order</h5>
        <table>
          <tr>
            <td align="right"><label>PO Number:&nbsp;&nbsp;</label></td>
            <td align="right"><input class="enter-input" id="po_input" type="text" class="medium-input"></input></td>
            <td><p id="po_error" style="color: red;" hidden>&nbsp;&nbsp;PO Number was incorrect</p></td>
          </tr><tr><td>&nbsp;</td></tr><tr>
            <td><label>Your Full Name:&nbsp;&nbsp;</label></td>
            <td><input class="enter-input" id="username_input" type="text" class="medium-input"></input></td>
            <td><p id="username_error" style="color: red;" hidden>&nbsp;&nbsp;Username was incorrect</p></td>
          </tr>
        </table><br>
        <button id="po_button" type="button" class="btn btn-default">Refresh</button>
      </div>
      
      <div id='po_controls' hidden>
        <hr>
        <a id='pdf_link' href='poPDF.php' class='btn btn-default' type='button' target='_blank'>Export PDF</a><br><br>
        
        <form>
          <div class="form-group">
            <label><input id="embed_po_check" type="checkbox"> Embed PO</input>
            </label>
            <br>
            <label><input id="custom_addr_check" type="checkbox"> Custom Email Address&nbsp;&nbsp;&nbsp; 
              <input id="custom_addr" class="large-input" name="email" type="text" placeholder="Enter Custom Email">
            </label>
            <br>
            <label><input id="custom_email_check" type="checkbox"> Custom Email Body</label>
            <br>
            <textarea id="custom_email_area" rows="16" cols="100" hidden></textarea>
          </div>
        </form>
        
        <form id="email_form" class="fileForm" action="poQuery.php" method="post" enctype="multipart/form-data">
          <!--<div>-->
            <h5>Mail PO</h5>
          <!--</div>-->
          <div id="email_success_div" class="alert alert-success" role="alert" hidden>Email Successfully Sent!</div>
          <div id="file_container">
            <label for="file_uploads">Choose attachments to upload</label>
            <input type="file" id="file_uploads" name="file_uploads" multiple>
          </div>
          <div class="preview">
            <p>No files currently selected for upload</p>            
          </div>
          <div>
            <p id="status"></p>
          </div>
          <div>
            <button id="mail_submit" type="submit" class="">Mail</button>
          </div>
       
        </form>
        
        <br>
        
        <div id="drawing_panel" class="panel panel-info">
          <div class="panel-heading">
            <h5 class="panel-title"><strong>PO Drawings</strong></h5>
          </div>
          <div id="drawing_container" class="panel-body srchResTable"></div>
        </div>
        <div hidden id="drawing_error_panel" class="panel panel-danger">
          <div class="panel-heading">
            <h5 class="panel-title"><strong>Drawing Errors</strong></h5>
          </div>
          <div id="drawing_error_container" class="panel-body"></div>
        </div>
        
      </div>      
      
      <div id="po_response_container" class="border-container">
      </div>
    </div>
  
      <!-- End page body -->
      
    </div>
    <div style="" class="page-margin-right margin right-margin-border main">
    
    </div>
  </div>
    
  <!--<script type="text/javascript" src="/lib/jquery-3.1.1/jquery.min.js"></script>  
  <script type="text/javascript" src="/lib/bootstrap-3.3.7/js/bootstrap.min.js"></script>-->
  
  <script type="text/javascript" src="/lib/spg_utils.js"></script>
  <script type="text/javascript" src="/lib/spin/spin.min.js"></script>  
  <script type="text/javascript">     
    
    var body = document.getElementsByTagName("BODY")[0];
    var defaultEmail = "";
    var poBody = null;
    //var email_html = "<br><div id='controls'><label>Email To:&nbsp;<input id='email_to_input' type='text' class='medium-input'></input>&nbsp;<button type='button' id='send_email_button' class='btn btn-default'>Send</button></label>&nbsp;&nbsp;&nbsp;<a href='poQuery.php?pdf=' class='btn btn-default' type='button' target='_blank'>Export PDF</a></div><br>";
    
    $(function() {
      
      // setup file input 
      var input = document.querySelector("#file_uploads");
      var preview = document.querySelector(".preview");
      
      input.style.opacity = 0;
      
      input.addEventListener("change", updateImageDisplay);
      
      var userdata = <?php
        require_once "/var/www/lib/php/spg_utils.php";
        
        $db_config_data = parse_ini_file("/var/www/config/it_database.ini");
        
        // connect to mariadb
        $conn = new mysqli($db_config_data["m_host"], $db_config_data["m_user"], $db_config_data["m_password"], $db_config_data["m_database"]);
        if ($conn->connect_errno) {
          echo "Failed to connect to MySQL: (" . $conn->connect_errno . ") " . $conn->connect_error;
          exit();
        }
        
        if ($_SERVER["PHP_AUTH_USER"] === "spgshopfloor" ||
            $_SERVER["PHP_AUTH_USER"] === "shopflr") {
              
          $managers_sql = "" .
            "SELECT NameFirst, NameLast " .
              "FROM names " .
              "WHERE IsSupervisor = 'Yes'";
          
          $managers_response = parseQueryResponse(sendQuery($conn, $managers_sql));
              
          $user = array(
            "id" => "12345",
            "username" => $_SERVER["PHP_AUTH_USER"],
            "NameFirst" => "SPG",
            "NameLast" => "ShopFloor",
            "Supervisor" => "Choose Option",
            "managerOptions" => $managers_response
          );
          echo json_encode($user);
        }
        else {
          
          $sql = "" .
            "SELECT id, username, NameFirst, NameLast, Supervisor " .
              "FROM names " .
              "WHERE username = '" . $_SERVER["PHP_AUTH_USER"] . "'";
          
          $response = parseQueryResponse(sendQuery($conn, $sql));
          echo json_encode($response[0]);
        }
      ?>;
      
      if (userdata != null) {
        $("#username_input").val(userdata.NameFirst + " " + userdata.NameLast);
        $("#username_input").prop("disabled", true);
      }
      
      $(".enter-input").on("keyup", function(e) {
        var key = event.keyCode || event.which;
        if (key === 13) {
          $("#po_button").trigger("click");
        }
      });
      
      $("#custom_email_check").on("click", function(e) {
        if ($("#custom_email_check").is(":checked")) {
          $("#custom_email_area").removeAttr("hidden");
		  $("#custom_email_area").css('display', 'block');
        }
        else {
          $("#custom_email_area").hide();
        }
      });
      
      $("#custom_addr_check").on("click", function(e) {
        if ($("#custom_addr_check").is(":checked")) {			
          $("#custom_addr").removeAttr("hidden");
		  $("#custom_addr").css('display','inline-block');
        }else {			
          $("#custom_addr").hide();
        }
      });
      
      $("#embed_po_check").on("click", function(e) {
        console.log("embedding po");
        if ($("#embed_po_check").is(":checked")) {          
          var po_html = document.getElementById('po_container').innerHTML;
          $("#custom_email_area").val("<po_export>");
        }
        else {
          $("#custom_email_area").val(defaultEmail);
        }
      });
    
      // document ready
      $("#po_button").on("click", function(e) {
        var spinner = new Spinner().spin();
        body.appendChild(spinner.el);
        
        $.ajax({
          url: "poQuery.php",
          method: "GET",
          data: {
            po: $("#po_input").val(),
            username: $("#username_input").val()
          },
          dataType: "text",
          success: function(response, textStatus, jqXHR) {
            
            resetUI();
            
            body.removeChild(spinner.el);
            
            //console.log(response);
            
            if (response == "username error") {
              $("#username_error").removeAttr("hidden");
			  $("#username_error").css('display', 'block');
            }
            else if (response == "po error") {
              $("#po_error").removeAttr("hidden");
			  $("#po_error").css('display', 'block');
            }
            else if (isJSON(response)) {
              var data = JSON.parse(response);
			  //console.log("Data : " +JSON.stringify(data));
                           
              
              /* load default email from response */
              if (data.email && data.email != "") {
                $("#custom_email_area").val(data.email);
                defaultEmail = data.email;
              }
              
              /* load html from response */
              if (data.html != "") {
				               
                $("#pdf_link").prop("href", "poQuery.php?pdf=exp&po=" + $("#po_input").val() + "&username=" + $("#username_input").val());
                $("#po_controls").removeAttr("hidden");
                poBody = $("<div/>").html(data.html).text();
                
                $("#po_response_container").html(poBody);
                
                if (data.email_splice) {
                  for (var i = 0; i < data.email_splice.length; i++) {
                    alert("Warning: possible edi email " + data.email_splice[i] + " is not being included in vendor emails. You may want to use the \"Embed PO\" option.");
                  }
                }
                
                var part_descs = $(".part_desc_text");
                for (var i = 0; i < part_descs.length; i++)  {
                  var partDescText = $(part_descs[i]).text();
                  if (partDescText.toLowerCase().indexOf("kit") > -1) {
                    alert("Warning: This PO may contain an item that is a kit");
 
                    break;
                  }
                }
                
                if (data.drawings) {
                  for (var i = 0; i < data.drawings.length; i++) {
                    $("#drawing_container").append(
                      "<a id='drawlink" + i + "' class='btn btn default' href='poDrawing.php?dwg=" + data.drawings[i] + "' target='_blank'>" + data.drawings[i].split("/").join("") + "</a>&nbsp;"
                    );
                    //$("#drawlink" + i + " a").trigger("click");
                  }
                }
                if (data.drawing_errors) {
                  if (data.drawing_errors.length > 0) {
                    $("#drawing_error_panel").removeAttr("hidden");
					console.log("drawing error");
                  }
                  for (var i = 0; i < data.drawing_errors.length; i++) {
                    $("#drawing_error_container").append(
                      "<div class='alert alert-danger' role='alert'>" +
                        "<span class='glyphicon glyphicon-exclamation-sign' aria-hidden='true'></span>" +
                        "<span class='sr-only'>Error:</span>" +
                        "&nbsp;Drawing for " + data.drawing_errors[i].part_nbr + " does not exist at K://doc_con/DWG/REL/" + data.drawing_errors[i].file_str +
                      "</div>"
                    );
                  }
                }
              }
            }
            else {
              console.log("json error");
            }
            
            //console.log(textStatus);
            console.log(jqXHR);
          },
          error: function(e1, e2, e3) {
            //console.warn(e1);
            //console.warn(e2);
            //console.warn(e3);			
            resetUI();
            alert("There was an error in the database... contact IT at http://nd-force.entegris.com/it/ticket/");
          }
        });
      });
      
      $("#email_form").on("submit", function(e) {
        var spinner = new Spinner().spin();
        body.appendChild(spinner.el);
      
        e.preventDefault();
        
        var form = document.getElementById('email_form');
        var fileSelect = document.getElementById('file_uploads');
        var uploadButton = document.getElementById('mail_submit');
        var statusDiv = document.getElementById('status');
        
        var files = fileSelect.files;
        
        var formData = new FormData();
        
        for (var i = 1; i <= files.length; i++) {
          //console.log(files[i-1]);
          if (files[i-1].size >= 10000000 ) {
            statusDiv.innerHTML = 'This file is larger than 2MB. Sorry, it can’t be uploaded.';
            return;
          }
          
          formData.append("attachment_" + i, files[i-1], files[i-1].name);
        }
        
        // handle input values
        formData.append("po", $("#po_input").val());
        formData.append("username", $("#username_input").val());
        
        // detect custom emails
        var email = "";
        if ($("#custom_addr").val() != null && $("#custom_addr").val() != "") {
          email = $("#custom_addr").val();
        }
        formData.append("email", email);
        formData.append("email_state", "true");
        formData.append("email_body", $("#custom_email_area").val());
        
        var embed_po = "false";
        if ($("#embed_po_check").is(":checked")) {
          embed_po = "true";
        }
        formData.append("embed_po", embed_po);
        
        var pdf_mode = "email";
        if ($("#embed_po_check").is(":checked")) {
          pdf_mode = "embed";
        }
        formData.append("pdf", pdf_mode);        
        
        // Set up AJAX request
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/mat/gp-slo_po/poQuery.php", true)
        
        xhr.onload = function() {
          if (xhr.status === 200) {
            statusDiv.innerHTML = "File(s) uploaded successfully...";
          }
          else {
            statusDiv.innerHTML = 'An error occurred while uploading the file(s). Try again';
          }
          body.removeChild(spinner.el);
        };
        
        // send the data
        xhr.send(formData);
        
        
        $("#email_success_div").removeAttr("hidden");
		$("#email_success_div").css('display', 'block');
        
      });
      
      function resetUI() {
        $("#po_error").hide();
        $("#username_error").hide();
        $("#drawing_error_panel").hide();
        $("#drawing_container").empty();
        $("#drawing_error_container").empty();
        
        // uncheck boxes and hide appropriate inputs
        $("#custom_addr_check").prop("checked", false);
        $("#custom_addr").hide();
        $("#custom_addr").val("");
        $("#embed_po_check").prop("checked", false);
        $("#custom_email_check").prop("checked", false);
        $("#custom_email_area").hide();
        
        $("po_controls").hide();
        $("po_container").hide();
        
        $("#email_success_div").hide();
        document.getElementById('status').innerHTML = "";
        
        // reset file input
        var element = document.getElementById("file_uploads");
        
        clearInputFile(element);
        updateImageDisplay();
      }
      
      function updateImageDisplay() {
        while(preview.firstChild) {
          preview.removeChild(preview.firstChild);
        }

        var curFiles = input.files;
        if(curFiles.length === 0) {
          var para = document.createElement('p');
          para.textContent = 'No files currently selected for upload';
          preview.appendChild(para);
        } else {
          var list = document.createElement('ol');
          preview.appendChild(list);
          for(var i = 0; i < curFiles.length; i++) {
            var listItem = document.createElement('li');
            var para = document.createElement('p');
            if(validFileType(curFiles[i])) {
              para.textContent = 'File name ' + curFiles[i].name + ', file size ' + returnFileSize(curFiles[i].size) + '.';
              var image = document.createElement('img');
              image.src = window.URL.createObjectURL(curFiles[i]);

              listItem.appendChild(image);
              listItem.appendChild(para);

            } else {
              para.textContent = 'File name ' + curFiles[i].name + ', file size ' + returnFileSize(curFiles[i].size) + '.';
              listItem.appendChild(para);
            }

            list.appendChild(listItem);
          }
        }
      }
      
      var fileTypes = [
        'image/jpeg',
        'image/pjpeg',
        'image/png'
      ]

      function validFileType(file) {
        for(var i = 0; i < fileTypes.length; i++) {
          if(file.type === fileTypes[i]) {
            return true;
          }
        }

        return false;
      }
      
    });
    
  </script>
  <script type="text/javascript" src="http://nd-wind.entegris.com/gp-slo/gp-slo.js"></script>
    <script type="text/javascript">
      $(document).ready(function () {
        $('#pageTitleDiv').html("");
        $('#pageTitleDiv').html("<h5>Purchase Order</h5>");
		$('#shortPageTitleDiv').html("");
		$('#shortPageTitleDiv').html("<h5>PO</h5>");
      })
    </script>
</body>
</html>
