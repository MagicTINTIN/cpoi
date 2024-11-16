<?php session_start();

// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

include_once("../db.php");
include_once("includes/utils.php");
$db = dbConnect();

function optEcho(string $str): void
{
    if (!isset($_REQUEST["qr"]))
        echo $str;
}

function checkValidCodeSilent(string $str): bool
{
    $val = strlen($str) <= 20 && strlen($str) >= 5;
    return $val;
}

function checkValidCode(string $str): bool
{
    $val = checkValidCodeSilent($str);
    if (!$val) optEcho("CPOI ERROR: Code is not in a valid format");
    return $val;
}

function checkValidValue(string $str): bool
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $val = strlen($str) <= 1800;
        if (!$val) optEcho("CPOI ERROR [GET]: Value is not in a valid format (max length = 1800)");
        return $val;
    } else {
        $val = strlen($str) <= 60000;
        if (!$val) optEcho("CPOI ERROR [POST]: Value is not in a valid format (max length = 60000)");
        return $val;
    }
}


function passiveClean(): void
{
    global $db;

    // delete normal
    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE type=:type AND date < DATE_SUB(NOW(), INTERVAL 30 DAY_MINUTE)');
    $cpoiStatement->execute(['type' => '']);
    // delete unique
    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE type=:type AND date < DATE_SUB(NOW(), INTERVAL 30 DAY_MINUTE)');
    $cpoiStatement->execute(['type' => 'u']);
    // delete short life
    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE type=:type AND date < DATE_SUB(NOW(), INTERVAL 5 DAY_MINUTE)');
    $cpoiStatement->execute(['type' => 's']);
    // delete long life
    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE type=:type AND date < DATE_SUB(NOW(), INTERVAL 12 DAY_HOUR)');
    $cpoiStatement->execute(['type' => 'l']);
    // delete anything that would still be in the table
    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE date < DATE_SUB(NOW(), INTERVAL 24 DAY_HOUR)');
    $cpoiStatement->execute();
}

passiveClean();

// CREATE CLIPBOARD \\

function createClipboard(string $content, string $type = ""): void
{
    global $db;

    if (isset($_REQUEST["t"]) && $type == "") {
        if (htmlspecialchars($_REQUEST["t"]) == "u")
            $type = "u";
        if (htmlspecialchars($_REQUEST["t"]) == "l")
            $type = "l";
        if (htmlspecialchars($_REQUEST["t"]) == "s")
            $type = "s";
    }

    $moreinfo = "";
    if (isset($_REQUEST["m"]) && strlen(htmlspecialchars($_REQUEST["m"])) < 500) {
        $moreinfo = htmlspecialchars($_REQUEST["m"]);
    }

    $codeGen = false;
    $codeVal = "";
    while (!$codeGen) {
        if (isset($_REQUEST["l"]) && htmlspecialchars($_REQUEST["l"]) == "fr")
            $lines = file("data/frWords");
        else
            $lines = file("data/enWords");
        $word1 = $lines[array_rand($lines)];
        $word2 = $lines[array_rand($lines)];
        $word3 = $lines[array_rand($lines)];
        $codeVal = substr($word1, 0, strlen($word1) - 1) . "-" . substr($word2, 0, strlen($word2) - 1) . "-" . substr($word3, 0, strlen($word3) - 1);

        $cpoiStatement = $db->prepare('SELECT ID FROM cpoi WHERE code = :code');
        $cpoiStatement->execute([
            'code' => $codeVal
        ]);

        $codes = $cpoiStatement->fetchAll();
        if (sizeof($codes) == 0)
            $codeGen = true;
    }

    $sqlQuery = 'INSERT INTO cpoi(info, type, code, value) VALUES (:info, :type, :code, :value)';

    $insertCPoi = $db->prepare($sqlQuery);
    $insertCPoi->execute([
        'info' => $moreinfo,
        'type' => $type,
        'value' => $content,
        'code' => $codeVal
    ]);
    optEcho($codeVal);
    exit;
}

// create normal clipboard
if (isset($_REQUEST["c"]) && checkValidValue(htmlspecialchars($_REQUEST["c"]))) {
    createClipboard(htmlspecialchars($_REQUEST["c"]));
}

// create unique normal clipboard
if (isset($_REQUEST["uc"]) && checkValidValue(htmlspecialchars($_REQUEST["uc"]))) {
    createClipboard(htmlspecialchars($_REQUEST["uc"]), "u");
}

// aggregate clipboard
if (isset($_REQUEST["a"]) && checkValidValue(htmlspecialchars($_REQUEST["a"]))) {
    $split = explode(':', htmlspecialchars($_REQUEST["a"]), 2);
    $code = $split[0];
    $agg = $split[1];

    $cpoiStatement = $db->prepare('SELECT * FROM cpoi WHERE code = :code');
    $cpoiStatement->execute([
        'code' => htmlspecialchars($code)
    ]);

    $codes = $cpoiStatement->fetchAll();
    if (sizeof($codes) == 0) {
        optEcho("CPOI ERROR: " . htmlspecialchars($code) . " is not a valid clipboard!");
        exit;
    }

    if (strpos($codes[0]["info"], "const") !== false) {
        optEcho("CPOI ERROR: " . htmlspecialchars($code) . " is not editable!");
        exit;
    }
    $sqlQuery = 'UPDATE cpoi SET date = current_timestamp(), value = :value WHERE code = :code';

    $newValue = $codes[0]["value"] . $agg;

    if (strlen($newValue) > 60000) {
        optEcho("CPOI ERROR: Clipboard can not be longer than 60000 chars. " . htmlspecialchars($code) . " has not been updated.");
        exit;
    }
    $updateCpoi = $db->prepare($sqlQuery);
    $updateCpoi->execute([
        'code' => $code,
        'value' => $newValue
    ]);
    optEcho($updateCpoi->rowCount());
}


// DELETE CLIPBOARD \\

function deleteClipboard(string $code): void
{
    global $db;

    $cpoiStatement = $db->prepare('DELETE FROM cpoi WHERE code = :code');
    $cpoiStatement->execute([
        'code' => $code
    ]);
}

// manually delete clipboard
if (isset($_REQUEST["d"]) && checkValidCode(htmlspecialchars($_REQUEST["d"]))) {
    $cpoiStatement = $db->prepare('SELECT ID FROM cpoi WHERE code = :code');
    $cpoiStatement->execute([
        'code' => htmlspecialchars($_REQUEST["d"])
    ]);

    $codes = $cpoiStatement->fetchAll();
    if (sizeof($codes) == 0)
        optEcho("CPOI ERROR: " . htmlspecialchars($_REQUEST["d"]) . " is not a valid clipboard!");
    else {
        optEcho("Ok.");
        deleteClipboard(htmlspecialchars($_REQUEST["d"]));
    }
}


// PASTE CLIPBOARD \\

function pasting(string $input): int
{
    global $db;
    $cpoiStatement = $db->prepare('SELECT * FROM cpoi WHERE code = :code');
    $cpoiStatement->execute([
        'code' => $input
    ]);

    $codes = $cpoiStatement->fetchAll();
    if (sizeof($codes) == 0) {
        return 1;
    } else {
        if ($codes[0]["type"] == "u") {
            deleteClipboard($input);
        }

        optEcho($codes[0]["value"]);
        return 0;
    }
}

if (isset($_REQUEST["p"]) && checkValidCode(htmlspecialchars($_REQUEST["p"]))) {
    if (pasting(htmlspecialchars($_REQUEST["p"])) == 1)
        optEcho("CPOI ERROR: " . htmlspecialchars($_REQUEST["p"]) . " is not a valid clipboard!");
}


// AUTOMATIC CLIPBOARD \\

// create or paste easy clipboard
if (isset($_REQUEST["e"]) && checkValidValue(htmlspecialchars($_REQUEST["e"]))) {
    if (checkValidCodeSilent(htmlspecialchars($_REQUEST["e"]))) {
        if (pasting(htmlspecialchars($_REQUEST["e"])) == 1)
            createClipboard(htmlspecialchars($_REQUEST["e"]));
    } else
        createClipboard(htmlspecialchars($_REQUEST["e"]));
}

// POST ANSWERS \\

// basic pong
if (isset($_REQUEST["ping"])) {
    optEcho("pong!");
}

if (isset($_REQUEST["qr"]) || (!isset($_REQUEST["a"]) && !isset($_REQUEST["c"]) && !isset($_REQUEST["d"]) && !isset($_REQUEST["e"]) && !isset($_REQUEST["p"]) && !isset($_REQUEST["ping"]) && !isset($_REQUEST["uc"]))) {
    ?>
    <!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="./extensionWebsite/styles/vars.css">
    <link rel="stylesheet" href="./extensionWebsite/styles/common.css">
    <link rel="stylesheet" href="./extensionWebsite/styles/menu.css">
    <script src="./extensionWebsite/lib/qrcode.min.js"></script>

    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="viewport"
        content="user-scalable=no, initial-scale=1, maximum-scale=1, minimum-scale=1, width=device-width, height=device-height, target-densitydpi=device-dpi" />
    <title>CPOI</title>

    <meta name="author" content="MagicTINTIN">
    <meta name="description" content="Copy Paste Over Internet tool">

    <link rel="icon" type="image/x-icon" href="extensionWebsite/images/favicon.png">

    <meta property="og:type" content="website" />
    <meta property="og:title" content="CPOI">
    <meta property="og:description" content="Copy Paste Over Internet tool">

    <meta property="og:image" content="https://cpoi.magictintin.fr/extensionWebsite/images/favicon.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="Logo of CPOI">

    <meta property="og:url" content="https://cpoi.magictintin.fr/" />
    <meta data-react-helmet="true" name="theme-color" content="#207DFE" />
</head>

<body>
    <nav>
        <div class="valign"><span class="languageButton spanButton textButton" id="lang">EN</span></div>
        <div class="buttonGroup">
            <span class="qrButton spanButton" id="qrGenButton"><img src="./extensionWebsite/images/qrcode.svg" alt="QRcode"
                    class="navSvgBtn" id="qrIcon"></span>
            <div class="valign"><span class="settingsButton spanButton textButton" id="settings">⚙</span></div>
            <div class="valign"><span class="settingsButton spanButton textButton" id="topBackPolicy">＜</span></div>
        </div>

    </nav>
    <div id="infoTempPopUp">
        <h2></h2>
        <p></p>
    </div>
    <div id="getTheExtension">
        <span id="getBExt">Get the browser extension!</span>
        <div id="availableOn">
            <a href="https://addons.mozilla.org/en-US/firefox/addon/cpoi/" target="_blank"><img class="availFox" src="extensionWebsite/images/getFirefox.png"></a>
            <a href="https://chromewebstore.google.com/detail/cpoi/kdlpjmhiomfnlkgbeghbeocfafggcdof" target="_blank"><img class="availChrome" src="extensionWebsite/images/getChrome.png"></a>
        </div>
    </div>
    <h1>CPOI</h1>
    <main>
        <br>
        <div class="margintop"></div>
        <section id="easySection">
            <textarea id="autoInput" class="blue portal"></textarea>
            <p id="autoInputInfo" class="errorInfo"></p>

            <div class="btnCentering"><button tabindex="-1" class="ebtn" id="aButton">Copy/Paste ↓</button></div>

            <textarea id="autoOutput" class="orange portal"></textarea>
        </section>
        <section id="classicSection">
            <textarea id="dataInput" class="blue portal"></textarea>
            <p id="dataInputInfo" class="errorInfo"></p>

            <div class="btnCenteringForce">
                <button tabindex="-1" class="tbtn cbtn" id="cButton">Copy ↓</button>
                <button tabindex="-1" class="tbtn pbtn" id="pButton">Paste ↑</button>
            </div>

            <textarea id="codeInput" class="orange portal"></textarea>
            <p id="codeInputInfo" class="errorInfo"></p>

            <section id="advancedSection">
                <br>
                <div class="settingsDiv">
                    <h4>Advanced settings</h4>
                    <span class="settingButton spanButton" id="settingPost">POST</span>
                    <span class="settingButton spanButton" id="settingConst">Uneditable</span>
                    <span class="settingButton spanButton" id="settingReset">Reset</span>
                </div>
            </section>
        </section>
        <section id="qrCodeSection">
            <div class="btnCenteringForce">
                <div id="qrcode"></div>
            </div>
            <p id="qrValue"></p>
        </section>
        <section id="settingsSection">
            <h4 id="settingsH4">Settings</h4>
            <div class="settingsDiv">
                <h6 id="themeTitle">Theme</h6>
                <span class="settingButton spanButton" id="settingTheme">Dark</span>
            </div>
            <div class="settingsDiv">
                <h6>Mode</h6>
                <span class="settingButton spanButton" id="settingMode">Simplified</span>
            </div>
            <div class="settingsDiv">
                <h6 id="deleteTitle">Delete after</h6>
                <span class="settingButton spanButton" id="settingTimeDelete">30min</span>
            </div>
            <div class="settingsDiv">
                <h6>Instance</h6>
                <input type="text" value="https://cpoi.magictintin.fr" class="inputSettings" id="settingInstance" readonly="readonly">
            </div>
            <div class="btnCentering"><button class="tbtn pbtn" id="saveSettings">Save</button></div>

            <div class="settingsDiv">
                <p class="termsButton" id="displayTerms">Terms Of Use</p>
            </div>
        </section>
        <section id="termsSection">
            <h4 id="termsH4">Terms & Conditions of Use</h4>
            <article class="terms" id="enTerms">
                <h5>1. Introduction</h5>
                Welcome to our website/web extension CPOI enabling text copying between devices (the "Service"). By
                accessing this Service, you agree to these Terms of Use (TOU). We encourage you to read them carefully
                before using our services.<br>
                <br>
                <h5>2. Liability in Case of Malfunctions</h5>
                We strive to ensure the proper functioning of the Service. However, we are not liable for interruptions,
                errors, or malfunctions, including those arising during scheduled or unscheduled maintenance. You
                acknowledge that access to the Service may be temporarily interrupted for technical reasons.<br>
                <br>
                <h5>3. Use of the Service</h5>
                The Service allows users to copy text between devices. Users must use it for legitimate purposes and in
                compliance with applicable laws. The Service is provided under the GPL v3.0 license, meaning anyone is
                free to create their own instance of the Service. You can access the project's source code and
                contribute via the following link: <a class="licenseLink"
                    href="https://github.com/MagicTINTIN/cpoi">https://github.com/MagicTINTIN/cpoi</a>.<br>
                <br>
                <h5>4. Security Recommendations</h5>
                We strongly advise users not to share personal sensitive data, such as passwords, when using the
                Service. Any transmission of such information is at your own risk.<br>
                <br>
                <h5>5. Prohibited Content and User Conduct</h5>
                Users agree not to transmit illegal content through the Service. Additionally, you agree not to:<br>
                <br>
                (a) Copy, distribute, modify, recreate, reverse engineer, sell, or transfer any information or software
                obtained from the Service without adhering to the terms of the GPL v3.0 license. Modifications or
                derivatives must be shared under the same license.<br>
                <br>
                (b) Access (or attempt to access) the Service through any means other than those provided by the
                Service's interface. The use of robots, spiders, or any automated means to access, acquire, or monitor
                any part of the Service is forbidden.<br>
                <br>
                (c) Use the Service in a manner that damages, overloads, disables, or compromises our infrastructure or
                that of a third party.<br>
                <br>
                (d) Use the Service for illegal activities, including fraud, money laundering, identity theft, or any
                criminal activity.<br>
                <br>
                (e) Harass, threaten, defame, or violate the legal rights of other users.<br>
                <br>
                (f) Disrupt or interfere with access to the Service or the networks connected to the Service.<br>
                <br>
                (g) Upload files containing viruses, malware, or other software designed to harm the Service or other
                users' devices.<br>
                <br>
                (h) Download any file that you know, or reasonably should know, cannot be legally distributed in this
                manner.<br>
                <br>
                (i) Probe, scan, or test the vulnerability of the Service or any connected networks or devices, or
                breach security measures.<br>
                <br>
                (j) Disrupt or compromise the security of the Service or any networks connected to the Service.<br>
                <br>
                (k) Use the Service for unlawful purposes or to encourage illegal activities.<br>
                <br>
                (l) Violate any applicable laws or regulations in your country or internationally.<br>
                <br>
                (m) Disseminate false, inaccurate, or misleading information through the Service.<br>
                <br>
                <h5>6. Open Source License and Creating Instances</h5>
                The Service is an open-source project under the GPL v3.0 license. The source code is publicly available,
                and anyone is free to create their own instance of the Service, modify it, and redistribute it under the
                same license terms. You can access the project repository here: <a class="licenseLink"
                    href="https://github.com/MagicTINTIN/cpoi">https://github.com/MagicTINTIN/cpoi</a>.<br>
                <br>
                <h5>7. Service and TOU Modifications</h5>
                We reserve the right to modify, suspend, or discontinue all or part of the Service at any time without
                notice. Additionally, we may amend these TOU at any time. Changes will be effective upon posting on this
                page.<br>
                <br>
                <h5>8. Intellectual Property</h5>
                All content available through the Service, including text, graphics, logos, icons, images, and audio
                clips, is the exclusive property of our company or its content providers, unless otherwise noted under
                the GPL v3.0 license. Any contributions to the project must comply with this license.<br>
                <br>
                <h5>9. Limitation of Liability</h5>
                In no event shall we be liable for any direct, indirect, consequential, or punitive damages arising from
                the use or inability to use the Service.<br>
                <br>
                <h5>10. Governing Law</h5>
                These TOU are governed by the laws of France.<br>
            </article>
            <article class="terms" id="frTerms">
                <h5>1. Introduction</h5>
                Bienvenue sur notre site/extension web CPOI permettant la copie de texte entre appareils ("Service"). En
                accédant à ce Service, vous acceptez les présentes Conditions Générales d'Utilisation (CGU). Nous vous
                invitons à les lire attentivement avant toute utilisation de nos services.<br>
                <br>
                <h5>2. Responsabilité en cas de dysfonctionnement</h5>
                Nous mettons tout en œuvre pour assurer le bon fonctionnement du Service. Toutefois, nous ne pouvons
                être tenus responsables des interruptions, erreurs ou dysfonctionnements, y compris ceux survenant lors
                des maintenances programmées ou imprévues. Vous reconnaissez que l'accès au Service peut être
                temporairement interrompu pour des raisons techniques.<br>
                <br>
                <h5>3. Utilisation du Service</h5>
                Le Service permet la copie de texte entre appareils. Les utilisateurs doivent l'utiliser à des fins
                légitimes et dans le respect des lois applicables. Le Service est fourni sous licence GPL v3.0, ce qui
                signifie que chacun est libre de créer sa propre instance du Service. Vous pouvez accéder au code source
                du projet et contribuer via le lien suivant : <a class="licenseLink"
                    href="https://github.com/MagicTINTIN/cpoi">https://github.com/MagicTINTIN/cpoi</a>.<br>
                <br>
                <h5>4. Recommandations de sécurité</h5>
                Nous incitons fortement les utilisateurs à ne pas partager de données personnelles sensibles, telles que
                des mots de passe, lors de l'utilisation du Service. Toute transmission de telles informations se fait à
                vos propres risques.<br>
                <br>
                <h5>5. Contenus interdits et comportement des utilisateurs</h5>
                Les utilisateurs s'engagent à ne pas transmettre de contenus illégaux via le Service. De plus, vous vous
                engagez à ne pas :<br>
                <br>
                (a) Copier, distribuer, modifier, recréer, désassembler, ingénierie inverse, vendre ou transférer toute
                information ou logiciel obtenu via le Service sans respecter les termes de la licence GPL v3.0. Les
                modifications ou dérivations doivent être partagées sous la même licence.<br>
                <br>
                (b) Accéder (ou tenter d'accéder) au Service par des moyens autres que ceux fournis par l'interface du
                Service. L'utilisation de techniques telles que les robots, les spiders ou tout autre procédé automatisé
                pour accéder, acquérir ou surveiller des parties du Service est interdite.<br>
                <br>
                (c) Utiliser le Service de manière à endommager, surcharger, désactiver ou compromettre notre
                infrastructure ou celle d'un tiers.<br>
                <br>
                (d) Utiliser le Service pour des activités illégales, telles que la fraude, le blanchiment d'argent, le
                vol d'identité ou toute autre activité criminelle.<br>
                <br>
                (e) Harceler, menacer, diffamer ou violer les droits légaux des autres utilisateurs.<br>
                <br>
                (f) Perturber ou interférer avec l'accès au Service ou aux réseaux connectés au Service.<br>
                <br>
                (g) Télécharger des fichiers contenant des virus, logiciels malveillants ou autres programmes
                susceptibles de nuire au Service ou aux appareils d'autrui.<br>
                <br>
                (h) Télécharger tout fichier que vous savez ou devriez raisonnablement savoir ne peut être légalement
                distribué de cette manière.<br>
                <br>
                (i) Tester la vulnérabilité du Service, de ses réseaux ou dispositifs connectés, ou enfreindre les
                mesures de sécurité.<br>
                <br>
                (j) Perturber ou compromettre la sécurité du Service ou des réseaux connectés au Service.<br>
                <br>
                (k) Utiliser le Service pour des fins illégales ou pour encourager des activités illégales.<br>
                <br>
                (l) Violer toute loi ou réglementation applicable dans votre pays ou à l'international.<br>
                <br>
                (m) Diffuser des informations fausses, inexactes ou trompeuses via le Service.<br>
                <br>
                <h5>6. Licence Open Source et Création d'Instances</h5>
                Le Service est un projet open source sous licence GPL v3.0. Le code source est disponible publiquement
                et chacun est libre de créer sa propre instance du Service, de le modifier, et de le redistribuer sous
                les mêmes termes de la licence. Vous pouvez accéder au dépôt du projet ici : <a class="licenseLink"
                    href="https://github.com/MagicTINTIN/cpoi">https://github.com/MagicTINTIN/cpoi</a>.<br>
                <br>
                <h5>7. Modifications du Service et des CGU</h5>
                Nous nous réservons le droit de modifier, suspendre ou interrompre temporairement ou définitivement tout
                ou partie du Service, à tout moment, sans préavis. De plus, nous pouvons modifier les présentes CGU à
                tout moment. Les modifications seront effectives dès leur publication sur cette page.<br>
                <br>
                <h5>8. Propriété intellectuelle</h5>
                Tout le contenu disponible sur le Service, y compris les textes, graphiques, logos, icônes, images,
                clips audio, est la propriété exclusive de notre société ou de ses fournisseurs de contenu, sauf
                indication contraire dans la licence GPL v3.0. Toute contribution au projet doit respecter cette
                licence.<br>
                <br>
                <h5>9. Limitation de responsabilité</h5>
                En aucun cas, nous ne saurions être tenus responsables des dommages directs, indirects, consécutifs ou
                punitifs résultant de l'utilisation ou de l'incapacité à utiliser le Service.<br>
                <br>
                <h5>10. Droit applicable</h5>
                Les présentes CGU sont régies par les lois Françaises.<br>
            </article>
            <p id="privacyLinkHolder"><a href="" onclick="showPrivacy(() => {showTerms()}); return false;"
                    id="privacyLink">Privacy Policy</a></p>
            <p id="byClickText">
                By clicking "I agree" or using our services, you agree to the TOU above.
            </p>
            <div class="btnCentering"><button class="tbtn pbtn" id="iagreeButton">I agree</button></div>
        </section>
        <section id="policySection">
            <h4 id="privacyH4">Privacy Policy</h4>
            <article class="terms" id="enPrivacy">
                <h5>Data Collection and Use</h5>
                The CPOI website and extension allow users to copy and paste text between different devices. The copied
                data is
                transmitted to our server and temporarily stored for the duration chosen by the user (from immediate
                deletion after pasting up to a maximum of 12 hours). Once this time has expired, the data is
                automatically deleted. We do not collect or retain any personal or sensitive information.<br>
                <br>
                <h5>Data Sharing</h5>
                The data transmitted through the extension is not shared with any third parties. It is strictly used to
                enable the copy-paste functionality between devices and is not exploited for any other purposes.<br>
                <br>
                <h5>Data Security</h5>
                All data transmissions are secured using encrypted connections (HTTPS). Users are advised not to enter
                personal sensitive information such as passwords or financial data into the extension.<br>
                <br>
                <h5>User Rights</h5>
                Users have the right to control the duration for which their data is stored on our server (from
                immediate deletion to up to 12 hours). The extension does not collect any data without the user's
                explicit action, and the data is permanently deleted after the time defined by the user.<br>
                <br>
                <h5>Changes to the Privacy Policy</h5>
                We reserve the right to update this privacy policy. Any changes will be communicated through an update
                to the extension or on our official website.<br>
                <br>
                <h5>Contact</h5>
                For any questions regarding data protection, please contact me at <span
                    id="nemel">ma<span>gi&#x200B;ct</span>in&#8203;ti<span id="split">n&commat;</span>pro<span
                        id="uselessText">t&#x200B;on</span><span>.me</span></span>.<br>.<br>
            </article>
            <article class="terms" id="frPrivacy">
                <h5>Collecte et utilisation des données</h5>
                Le site web et l'extension CPOI permettent aux utilisateurs de copier et coller du texte entre
                différents appareils. Les
                données copiées sont transmises à notre serveur uniquement pour être temporairement stockées pendant la
                durée choisie par l'utilisateur (allant de suppression immédiate après collage jusqu'à un maximum de 12
                heures). Une fois ce délai expiré, les données sont automatiquement supprimées. Nous ne collectons ni ne
                conservons d'informations personnelles ou sensibles.<br>
                <br>
                <h5>Partage des données</h5>
                Les données transmises via l'extension ne sont partagées avec aucune tierce partie. Elles sont
                strictement utilisées pour permettre la fonctionnalité de l'extension (copier-coller entre appareils) et
                ne sont en aucun cas exploitées à d'autres fins.<br>
                <br>
                <h5>Sécurité des données</h5>
                Toutes les données transmises sont sécurisées via des connexions chiffrées (HTTPS). Les utilisateurs ne
                doivent pas entrer d'informations personnelles sensibles dans l'extension, telles que des mots de passe
                ou des informations financières.<br>
                <br>
                <h5>Droits des utilisateurs</h5>
                Les utilisateurs ont le droit de contrôler la durée pendant laquelle leurs données sont conservées sur
                notre serveur (de suppression immédiate jusqu'à 12 heures). L'extension ne collecte aucune donnée sans
                l'action explicite de l'utilisateur, et ces données sont supprimées de manière permanente après le temps
                défini par l'utilisateur.<br>
                <br>
                <h5>Modifications de la politique de confidentialité</h5>
                Nous nous réservons le droit de mettre à jour cette politique de confidentialité. Toute modification
                sera communiquée par une mise à jour de l'extension ou sur notre site officiel.<br>
                <br>
                <h5>Contact</h5>
                Pour toute question concernant la protection des données, veuillez me contacter à <span
                    id="nemel">ma<span>gi&#x200B;ct</span>in&#8203;ti<span id="split">n&commat;</span>pro<span
                        id="uselessText">t&#x200B;on</span><span>.me</span></span>.<br>
            </article>
            <div class="btnCentering"><button class="tbtn pbtn" id="backFromPolicy" onclick="home()">Back</button></div>
        </section>
        <p id="footerLinkContainer"><a tabindex="-1" href="https://cpoi.magictintin.fr"
                class="bottomLink">cpoi.magictintin.fr</a> | <a href="" onclick="showTerms(); return false;"
                class="bottomLink" id="bottomTerms">Terms Of Use</a> | <a href="" onclick="showPrivacy(); return false;"
                class="bottomLink" id="bottomPrivacy">Privacy Policy</a>
        </p>
    </main>

    <script src="./extensionWebsite/scripts/memory.js"></script>
    <script src="./extensionWebsite/scripts/settings.js"></script>
    <script src="./extensionWebsite/scripts/srvmenu.js"></script>
</body>

</html>
    <?php
}