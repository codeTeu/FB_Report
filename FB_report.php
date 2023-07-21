<!--Developer: Marjorie Teu, 2023-->

<?php
//
require_once('./admin.php');

define('ABSPATH', dirname(__FILE__) . '/');
require_once(ABSPATH . 'wp.php');

//LINKS TO DB
($link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME)) || die("Couldn't connect to MySQL");

// Use the Toronto time zone.  Note: PHP uses the UTC time zone by default, which is not wanted.
date_default_timezone_set('America/Toronto');
?>

<!--PAGE ELEMENTS-->
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>FB-mem-report</title>

    <style>
        .csvButton {
            background: none;
            border: 1px solid green;
            box-shadow: none;
            padding: 10px;
        }

        .csvButton:hover {
            background: lightgray;
        }

        .csvLink {
            text-decoration: none;
            text-shadow: none;
        }

        th {
            padding-left: 5px;
            padding-right: 5px;
        }

        .btnSubmitStyle {
            padding: 10px
        }

        #tableDataID table,
        #tableDataID tr,
        #tableDataID th,
        #tableDataID td {
            border: 1px solid black;
            border-collapse: collapse;
        }

        #tableDataID {
            margin: 30px;
            text-align: center;
            border-collapse: collapse;
        }
    </style>

<body>
    <div style="margin:10px;">
        <h3>Press submit to continue</h3>

        <form action="<?= $_SERVER['PHP_SELF']; ?>" method="post">
            <input type="submit" name="submit" value="Submit" class="btnSubmitStyle" />
        </form>

    </div>
    <div>
        <?php
        $time_start = microtime(true);

        if ($_POST['submit']) {

            /**************************************/
            /************** VARIABLES *************/
            /**************************************/
            $mem_id;
            $in_fb;
            $fb_profile_name;
            $actions;           //not used, as guide  only
            $expiry_date;       //not used, as guide  only
            $expired_on;        //not used, as guide  only
            $mem_role;          //not used, as guide  only
            $smart_waiver;
            $is_demoted;        //not used, as guide  only
            $fb_grace_days_allowed = 14;

            $totalMember = 0;
            $fbCsvString;       //variable where the comma delimited file will be saved before being copied to the csv file


            /*********************************************************/
            /************** CREATE/DROP/TRUNCATE TABLE  **************/
            /********************************************************/
            //RESETS FB_Report BUT KEEP THE TABLE
            //mysqli_query($link, "TRUNCATE TABLE FB_Report;");

            //DROPS FB_Report TABLE
            mysqli_query($link, "DROP TABLE IF EXISTS FB_Report");

            //CREATES FB_Report TABLE -- where we'll store all members data to be printed
            $query = "CREATE TABLE FB_Report(
                        id bigint PRIMARY KEY NOT NULL,
                        username VARCHAR(100) NOT NULL,
                        first_name VARCHAR(100) NOT NULL,
                        last_name VARCHAR(100) NOT NULL,
                        email VARCHAR(100) NOT NULL,
                        in_fb VARCHAR(100) NOT NULL,
                        fb_profile_name VARCHAR(100) NOT NULL,
                        actions VARCHAR(100) NOT NULL,
                        expiry_date VARCHAR(100) NOT NULL,
                        expired_on VARCHAR(100) NOT NULL,
                        role VARCHAR(100) NOT NULL,
                        smartWaiver_dates VARCHAR(100) NOT NULL,
                        is_demoted VARCHAR(3) DEFAULT 'NO')";
            mysqli_query($link, $query);


            /*************************************************************************************************/
            /************** COPY id, username, first_name, last_name, email, role TO  TABLE  *****************/
            /************************************************************************************************/
            $copyData = "INSERT INTO FB_Report(id, username, first_name, last_name, email, role)
                            SELECT
                                u.ID,
                                u.user_login AS username,
                                um1.meta_value AS first_name,
                                um2.meta_value AS last_name,
                                u.user_email AS email,
                                CASE
                                    WHEN um.meta_value LIKE '%s2member_level1%' THEN 'Member'
                                    WHEN um.meta_value LIKE '%subscriber%' THEN 'Guest'
                                END AS role
                            FROM
                                mem_user u,
                                meta_table um,
                                meta_table um1,
                                meta_table um2
                            WHERE
                                u.ID = um.user_id
                                AND u.ID = um1.user_id
                                AND u.ID = um2.user_id
                                AND um.meta_key = 'wp_capabilities'
                                AND (
                                    um.meta_value LIKE '%s2member_level1%'
                                    OR um.meta_value LIKE '%subscriber%'
                                )
                                AND um1.meta_key = 'first_name'
                                AND um2.meta_key = 'last_name'
                            ORDER BY u.ID ASC
                        ";
            mysqli_query($link, $copyData);

            /*********************************************************/
            /************** GETS ALL IDs TO BE PROCESSED *************/
            /*********************************************************/
            $ids_to_be_processed = mysqli_query($link, "SELECT id FROM FB_Report");

            /******************************************************************************/
            /************** UPDATE $in_fb AND $fb_name on FB_Report *************/
            /******************************************************************************/
            //in there's a row retrieved, do this
            if (mysqli_num_rows($ids_to_be_processed)) {
                while ($mem_row = mysqli_fetch_array($ids_to_be_processed)) {
                    //retieves the serialized data of the member # $mem_
                    $s2m_query = "SELECT meta_value FROM meta_table
                                    WHERE user_id = " . $mem_row['id'] . "
                                    AND meta_key = 'mem_fields'
                                    LIMIT 1";
                    $s2m_result = mysqli_query($link, $s2m_query);

                    //if there's data retrieved, do this
                    if (mysqli_num_rows($s2m_result)) {
                        //unserialize the data
                        $s2m_row = mysqli_fetch_array($s2m_result);
                        $s2m_str = $s2m_row['meta_value'];
                        $s2m_obj = unserialize($s2m_str);

                        //checks if data values are empty/null then sets the default value for the variables
                        $in_fb                  = empty($s2m_obj['join_fb_group'])   ?     "no" :   $s2m_obj['join_fb_group'];
                        $fb_profile_name        = empty($s2m_obj['fb_name'])        || is_null($s2m_obj['fb_name']) ?           ""  :   $s2m_obj['fb_name'];
                        $smart_waiver           = empty($s2m_obj['smart_waiver'])   || is_null($s2m_obj['smart_waiver']) ?      ""  :   $s2m_obj['smart_waiver']; //all dates

                        //UPDATE fb_name and in_fb FB_Report TABLE THAT MATCHES THE mem_id
                        $copyData = "UPDATE FB_Report
                                        SET fb_profile_name = '" . $fb_profile_name . "' ,
                                            in_fb= UPPER('" . $in_fb . "'),
                                            smartWaiver_dates = '" . $smart_waiver . "'
                                        WHERE id =" . $mem_row['id'];
                        mysqli_query($link, $copyData);
                    }
                } //end of loop row ID
            } //  END UPDATE $in_fb AND $fb_name on FB_Report

            /*********************************************************/
            /************** REMOVE UNECESSARY ROWS *******************/
            /*********************************************************/
            //include only members and Guests (non-members) where join_fb_group is yes
            $delete_query = "DELETE FROM FB_Report
                                    WHERE
                                        role = 'guest' AND in_fb IS NULL
                                        OR
                                        role = 'guest' AND in_fb = ''
                                        OR
                                        role = 'guest' AND in_fb = 'no' ";
            mysqli_query($link, $delete_query);


            /*********************************************************/
            /************** GETS ALL IDs TO BE PROCESSED *************/
            /*********************************************************/
            $id_query_result2 = mysqli_query($link, "SELECT id FROM FB_Report");


            /**********************************************************************************************/
            /************** UPDATE $expiry_date AND $expired_on on FB_Report *****************************/
            /*********************************************************************************************/
            if (mysqli_num_rows($id_query_result2)) {
                while ($mem_row = mysqli_fetch_array($id_query_result2)) {

                    //join meta_table TABLE and FB_Report TABLE with matching id
                    $update_query = "UPDATE FB_Report
                                            INNER JOIN(
                                                        SELECT
                                                            user_id,
                                                            FROM_UNIXTIME(meta_value,'%Y-%m-%d')                -- shows date only
                                                            -- FROM_UNIXTIME(meta_value,'%Y-%m-%d %h:%i:%s')    -- shows date and time
                                                            AS expiry_date
                                                        FROM
                                                            meta_table
                                                        WHERE
                                                            user_id = " . $mem_row['id'] . "
                                                            AND meta_key = 'mem_eot'
                                                            AND meta_value IS NOT NULL
                                                            AND meta_value <> ''
                                                        LIMIT 1
                                            ) AS umTable
                                            ON
                                            FB.id = umTable.user_id
                                            SET
                                            FB.expiry_date = umTable.expiry_date;
                                            ";
                    $exp_result = mysqli_query($link, $update_query);


                    //-------------------------------- UPDATE expired_on on FB_Report -------------------------------- //
                    //join memberships TABLE and FB_Report TABLE with matching id
                    //KEEP THE "ORDER BY DEC" , THERE ARE MULTIPLE eot_date_time DATES. WE ONLY NEED THE LATEST ONE
                    $update_query = "UPDATE
                                        FB_Report
                                        INNER JOIN(
                                                    SELECT
                                                        user_id,
                                                        DATE_FORMAT(eot_date_time, '%Y-%m-%d') as expired_on
                                                    FROM
                                                        memberships
                                                    WHERE
                                                        user_id = " . $mem_row['id'] . "
                                                        AND eot_date_time <> '0000-00-00 00:00:00'
                                                    ORDER BY
                                                        eot_date_time
                                                    DESC
                                                        LIMIT 1
                                        ) AS memTable
                                    ON
                                        FB.id = memTable.user_id
                                    SET
                                        FB.expired_on = memTable.expired_on
                                    WHERE is_demoted = 0;
                                    ";

                    mysqli_query($link, $update_query);
                }
            } // END OF UPDATE $expiry_date, $expiry_date on FB_Report

            // echo "<pre>";
            // print_r("----------- UPDATED expiry_date  ----------");
            // echo "</pre>";

            // echo "<pre>";
            // print_r("----------- UPDATED expired_on  ----------");
            // echo "</pre>";



            /*********************************************************************************/
            /************** DETERMIN WHO is_demoted on FB_Report *******************/
            /*********************************************************************************/
            //A demoted user is a user who used to be a Member, but was demoted to Guest before their membership expired.
            //changes all is_demoted to for all guest that have active/future expiry_date
            $update_query = "UPDATE FB_Report
                                    SET is_demoted = 'YES'
                                    WHERE role = 'guest'
                                    AND expiry_date !=''";

            mysqli_query($link, $update_query);

            // echo "<pre>";
            // print_r("----------- UPDATED is_demoted ----------");
            // echo "</pre>";


            /*********************************************************************************/
            /************** UPDATE action on FB_Report *****************************/
            /*********************************************************************************/
            //skip this Expired On  section if the user was "demoted"
            $update_query = "UPDATE
                                    FB_Report
                                SET
                                    actions = CASE
                                                WHEN role = 'member'
                                                    THEN ''
                                                WHEN role = 'guest' AND is_demoted = 'YES'
                                                    THEN 'Remove Demoted'
                                                WHEN role = 'guest' AND is_demoted = 'NO' AND expired_on=''
                                                    THEN 'error'
                                                WHEN role = 'guest' AND is_demoted = 'NO' AND 	DATEDIFF(CURRENT_TIMESTAMP,expired_on)>$fb_grace_days_allowed
                                                    THEN  'Remove'
                                                WHEN role = 'guest' AND is_demoted = 'NO'
                                                    AND DATEDIFF(CURRENT_TIMESTAMP,expired_on)<=$fb_grace_days_allowed
                                                    AND DATEDIFF(CURRENT_TIMESTAMP,expired_on)>=0
                                                    THEN CONCAT('Grace ', DATEDIFF(CURRENT_TIMESTAMP,expired_on))
                                            END;
                                ";
            mysqli_query($link, $update_query);

            // echo "<pre>";
            // print_r("----------- UPDATED is_demoted ----------");
            // echo "</pre>";

            /*--------------------------------------------------------------------------------------------------------*/
            /*---------------------------------------- CSV PREPARATION -----------------------------------------------*/
            /*--------------------------------------------------------------------------------------------------------*/

            /******************************************************/
            /************** CREATES THE CSV FILE ******************/
            /******************************************************/
            //create the .csv file
            $path = ".../fb-mem-report.csv";   //  temp
            $filePath = fopen("$path", "w");

            //adds the header
            $fbCsvString =  '"id", ';
            $fbCsvString .=  '"username", ';
            $fbCsvString .=  '"first_name", ';
            $fbCsvString .=  '"last_name", ';
            $fbCsvString .=  '"email", ';
            $fbCsvString .=  '"in_fb" ,';
            $fbCsvString .=  '"fb_profile_name", ';
            $fbCsvString .=  '"actions", ';
            $fbCsvString .=  '"expiry_date", ';
            $fbCsvString .=  '"expired_on", ';
            $fbCsvString .=  '"role", ';
            $fbCsvString .=  '"smartWaiver_dates", ';
            $fbCsvString .=  '"is_demoted"' . "\n";


            /*--------------------------------------------------------------------------------------------------------*/
            /*---------------------------------------- DISPLAY TABLE DATA ON THE SCREEN ------------------------------*/
            /*--------------------------------------------------------------------------------------------------------*/

            /*******************************************************/
            /************** RETRIEVE DATA FROM DB ******************/
            /*******************************************************/
            $fb_report_query = "SELECT
                                    *
                                FROM
                                `FB_Report`
                                ORDER BY
                                role DESC,
                                in_fb DESC,
                                fb_profile_name,
                                first_name,
                                last_name;
                                ";
            $fb_report_result = mysqli_query($link, $fb_report_query);

            /**************************************************************/
            /************** CREATES THE DOWNLOAD BUTTON ******************/
            /*************************************************************/
            print " <div>
                        <button class='csvButton'>
                            <a class='csvLink' href=\"csv-files/fb-mem-report.csv\" target=\"_blank\" style=\"color: Green;\">Download CSV file...</a>
                        </button>
                    </div>
                    ";

            /************************************************/
            /************** CREATES TABLE  ******************/
            /************************************************/
            echo "<table id='tableDataID'>
                    <tr>
                        <th>id</th>
                        <th>username</th>
                        <th>first_name</th>
                        <th>last_name</th>
                        <th>Email</th>
                        <th>in_fb</th>
                        <th>fb_profile_name</th>
                        <th>actions</th>
                        <th>expiry_date</th>
                        <th>expired_on</th>
                        <th>role</th>
                        <th>smartWaiver_dates</th>
                        <th>is_demoted</th>
                    </tr>
                ";

            //if there's a retireved data
            if (mysqli_num_rows($fb_report_result)) {
                while ($mem_row = mysqli_fetch_array($fb_report_result)) {
                    // echo "<pre>";
                    // print_r($mem_row);
                    // echo "</pre>";

                    //prints member data on the screen by row
                    echo "<tr>";
                    echo "<td>" . $mem_row['id'] . "</td>";
                    echo "<td>" . $mem_row['username'] . "</td>";
                    echo "<td>" . $mem_row['first_name'] . "</td>";
                    echo "<td>" . $mem_row['last_name'] . "</td>";
                    echo "<td>" . $mem_row['email'] . "</td>";
                    echo "<td>" . $mem_row['in_fb'] . "</td>";
                    echo "<td>" . $mem_row['fb_profile_name'] . "</td>";
                    echo "<td>" . $mem_row['actions'] . "</td>";
                    echo "<td>" . $mem_row['expiry_date'] . "</td>";
                    echo "<td>" . $mem_row['expired_on'] . "</td>";
                    echo "<td>" . $mem_row['role'] . "</td>";
                    echo "<td>" . $mem_row['smartWaiver_dates'] . "</td>";
                    echo "<td>" . $mem_row['is_demoted'] . "</td>";
                    echo "<tr>";

                    $totalMember++;

                    //after printing data on the screen, copy that data in the string variable (comma delimited) to be saved later to the CSV file
                    $fbCsvString .=  '"' . $mem_row['id'] . '",';
                    $fbCsvString .=  '"' . $mem_row['username'] . '",';

                    //check first_name for apostrophes
                    if ($AposPosition = strpos($mem_row['first_name'], "'") > 0) {
                        $fbCsvString .=
                            '"' .
                            substr($mem_row['first_name'], 0, $AposPosition)    //first part of name (start to before apostrophe)
                            .
                            substr($mem_row['first_name'], $AposPosition)       //second part of name (apostrophe to end)
                            . '",';
                    } else {
                        $fbCsvString .=  '"' . $mem_row['first_name'] . '",';
                    }

                    //check last_name for apostrophes
                    if ($AposPosition = strpos($mem_row['last_name'], "'") > 0) {
                        $fbCsvString .=
                            '"' .
                            substr($mem_row['last_name'], 0, $AposPosition)     //first part of name (start to before apostrophe)
                            .
                            substr($mem_row['last_name'], $AposPosition)        //second part of name (apostrophe to end)
                            . '",';
                    } else {
                        $fbCsvString .=  '"' . $mem_row['last_name'] .  '",';
                    }

                    $fbCsvString .=  '"' . $mem_row['email']            . '",';
                    $fbCsvString .=  '"' . $mem_row['in_fb']            . '",';
                    $fbCsvString .=  '"' . $mem_row['fb_profile_name']  . '",';
                    $fbCsvString .=  '"' . $mem_row['actions']          . '",';
                    $fbCsvString .=  '"' . $mem_row['expiry_date']      . '",';
                    $fbCsvString .=  '"' . $mem_row['expired_on']       . '",';
                    $fbCsvString .=  '"' . $mem_row['role']             . '",';
                    $fbCsvString .=  '"' . $mem_row['smartWaiver_dates'] . '",';
                    $fbCsvString .=  '"' . $mem_row['is_demoted'] . '"' . "\n";
                }
            }

            echo "</table>";

            //save final comma delimited string to the csv file
            fputs($filePath, $fbCsvString);

            echo "<h1>TOTAL ROWS: " . $totalMember . "</h1>";
        }


        //shows how long it took to finish
        $time_end = microtime(true);
        $time = $time_end - $time_start;
        echo "It took $time seconds\n";
        ?>

</body>

</html>