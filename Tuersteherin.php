#!/usr/bin/env php
<?php
include("SmartIRC.php");

define("IRC_UNDERLINE", "\001");
define("IRC_BOLD", "\002");
define("IRC_ITALIC", "\026");
define("IRC_NORMAL", "\017");

class Tuersteherin {

    private $SmartIRC;

    const Nickname = "Dagobert";
    const Realname = "Dagobert Duck";
    
    const Server   = "irc.euirc.net";
    const Port     = 6667;
    const Channels = '#whf';

    const IdleTimeout = 0;
   
    private $LoggedIn = array();
    private $idleTime = array();
    private $UserUIDs = array();

    private $simpleKeywords = array(
        'eudar' => 'eudaR :o!',
        'metal' => 'metal \m/ :o',
        'hitler' => 'Commodore-Freak !_! *highlight* euda :>',
        'bier' => 'biertitten :>',
        'dagegen' => 'Ich bin für Kicken! :o'
    );

    private $searchEngines = array(
        'google' => 'http://www.google.de/search?q=',
        'googlepic' => 'http://www.google.de/images?q=',
        'lmgtfy' => 'http://lmgtfy.com/?q=',
        'wikipedia' => 'http://de.wikipedia.org/w/index.php?title=Spezial:Suche&search=',
        'whfsearch' => 'http://www.winhistory-forum.net/search.php?do=process&q='
    );

    function Tuersteherin() {
        $irc = $this->SmartIRC = &new Net_SmartIRC();
        $irc->setUseSockets(true);
        $irc->setChannelSyncing(true);
        $irc->setUserSyncing(true);
        $irc->setAutoReconnect(true);
        $irc->setDebug(SMARTIRC_DEBUG_ALL);

        setlocale(LC_ALL, 'de_DE');

        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '.*', $this, 'updateIdle');
        $irc->registerActionhandler(SMARTIRC_TYPE_NICKCHANGE, '.*', $this, 'updateUUID');
        $irc->registerActionhandler(SMARTIRC_TYPE_WHO, '.*', $this, 'setUUID');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUIT, '.*', $this, 'removeUUID');


        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!login', $this, 'login');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!logout$', $this, 'logout');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUERY, '^!admins$', $this, 'admins');
        $irc->registerActionhandler(SMARTIRC_TYPE_QUIT, '.*', $this, 'logout');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!kick\s.+', $this, 'kick');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!kickban\s.+', $this, 'kickban');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!whois\s.+', $this, 'whois');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!quit$', $this, 'quit');

        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '/^(hallo|huhu|hi)\s'.self::Nickname.'/i', $this, 'Huhu');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!toblerone(\s|$)', $this, 'Toblerone');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!8ball[1]?(\s|$)', $this, 'EightBall');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!say\s', $this, 'Say');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!sayme\s', $this, 'SayMe');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!popp\s', $this, 'Popp');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!dice(\s\d|$)', $this, 'Dice');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!?ping(\?|!|\.)?$', $this, 'Ping');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '/[-+]?[0-9]*[.,]?[0-9]+\s?chf/i', $this, 'CHFtoEUR');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '^!(time|date)(\s|$)', $this, 'Time');

        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '.*', $this, 'simpleKeywords');
        $irc->registerActionhandler(SMARTIRC_TYPE_CHANNEL, '!.+\s', $this, 'searchEngine');

        $irc->connect(self::Server, self::Port);
        $irc->login(self::Nickname, self::Realname);
        $irc->join(explode('|', self::Channels));

        if(self::IdleTimeout > 0) {
            $irc->registerTimehandler(5000, $this, 'checkIdle');
        }

        $irc->listen();
        $irc->disconnect();
    }

    function updateIdle(&$irc, &$ircdata) {
        $uuid = $this->createUUID($ircdata);

        if(!isset($this->UserUIDs[$uuid])) {
            $this->UserUIDs[$uuid] = $ircdata->nick;
        }

        if(!isset($this->idleTime[$ircdata->channel][$uuid])) {
            for($i=0;$i<4;$i++) {
                $this->idleTime[$ircdata->channel][$uuid][$i] = 0;
            }
        } else {
            for($i=3;$i>0;$i--) {
                $this->idleTime[$ircdata->channel][$uuid][$i] = 
                    $this->idleTime[$ircdata->channel][$uuid][$i-1];
            }
        }
        $timestamps = &$this->idleTime[$ircdata->channel][$uuid];
        $timestamps[0] = microtime(true);
        
        if(self::IdleTimeout != 0 && !$irc->isVoiced($ircdata->channel, $ircdata->nick)) {
            $irc->voice($ircdata->channel, $ircdata->nick);
        }
        
        if($timestamps[0] - $timestamps[3] < 2) {
            $irc->kick($ircdata->channel, $ircdata->nick, "Fluten des Kanals verboten!"); 
        }
    }

    function checkIdle(&$irc) {
        foreach(explode('|', self::Channels) as $channel) {
            $idleList = array();
            foreach($irc->getChannel($channel)->voices as $user=>$isVoiced) {
                $uuid = $this->getUUID($user);
                $timestamp = isset($this->idleTime[$channel][$uuid]) ?
                                $this->idleTime[$channel][$uuid][0] : 0;

                if(microtime(true) - $timestamp > self::IdleTimeout) {
                    $idleList[] = $user;
                }   
            }

            if(count($idleList) > 0) {
                $irc->mode($channel, '-'.str_repeat('v', count($idleList)).' '.implode(' ', $idleList));
            }
        }
    }

    function updateUUID(&$irc, &$ircdata) {
        $uuid = $this->createUUID($ircdata);
        $this->UserUIDs[$uuid] = $ircdata->message;
    }

    function setUUID(&$irc, &$ircdata) { 
        $raw_msg = &$ircdata->rawmessageex;
        if($raw_msg[1] == SMARTIRC_RPL_WHOREPLY) {
            $uuid = $raw_msg[4].'@'.$raw_msg[5];
            $this->UserUIDs[$uuid] = $raw_msg[7];
        }
    }

    function removeUUID(&$irc, &$ircdata) { 
        $uuid = $this->createUUID($ircdata);
        unset($this->UserUIDs[$uuid]);
        foreach($this->idleTime as $channel=>$user) {
            unset($this->idleTime[$channel][$uuid]);
        }
    }

    function getNickname($uuid) {
        return $this->UserUIDs[$uuid];
    }

    function getUUID($nickname) {
        return array_search($nickname, $this->UserUIDs);
    }

    function createUUID(&$ircdata) {
        return $ircdata->ident.'@'.$ircdata->host;
    }

    function quit(&$irc, &$ircdata) {
        if($this->checkLogin($ircdata->nick)) {
            $irc->quit("Heil Diskordia!");
        }
    }

    function whois(&$irc, &$ircdata) {
        $uuid = $this->getUUID($ircdata->messageex[1]);
        if($irc->isMe($ircdata->messageex[1])) {
            $msg = "Das bin ich selber.";
        } elseif($uuid === false) {
            $msg = "Fehler: Unbekannter Benutzer";
        } else {
            $msg = $uuid;
        }
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $msg);
    }

    function kickban(&$irc, &$ircdata) {
        $this->kick($irc, $ircdata, true);
    }

    function kick(&$irc, &$ircdata, $kickban = false) {
        if($irc->isOpped($ircdata->channel)) {
            $user = $ircdata->messageex[1];
            if($this->checkLogin($ircdata->nick)) {
                if($kickban && ($uuid = $this->getUUID($user)) !== false) {
                    $irc->ban($ircdata->channel, '*!'.$uuid);
                }
                $irc->kick($ircdata->channel, $user, "WUTSCHNAUBZETTER");
            } else {
                $irc->kick($ircdata->channel, $ircdata->nick, "Du hast mir gar nichts zu sagen.");
            }
        }
    }

    function login(&$irc, &$ircdata) {
        if(!isset($ircdata->messageex[2])) return;
        $user = $ircdata->messageex[1];
        $pass = $ircdata->messageex[2];
        $Admins = parse_ini_file("Admins.txt");
        
        if(isset($Admins[$user]) && sha1($pass) == $Admins[$user]) {
            $uuid = $this->createUUID($ircdata);
            $this->LoggedIn[$uuid] = true;
            $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged in");
        }
    }

    function checkLogin($nick) {
        $uuid = $this->getUUID($nick);
        return isset($this->LoggedIn[$uuid]) ? $this->LoggedIn[$uuid] : false;
    }

    
    function admins(&$irc, &$ircdata) {
        if(!$this->checkLogin($ircdata->nick)) return;
        $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged in admins:");
        foreach($this->LoggedIn as $uuid=>$loggedin) {
            if($loggedin) {
                $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, $this->getNickname($uuid));
            }
        }
    }
    
    function logout(&$irc, &$ircdata) { //FIXME funktioniert nicht bei quit
        if($this->checkLogin($ircdata->nick)) {
            unset($this->LoggedIn[$ircdata->nick]);
            $irc->message(SMARTIRC_TYPE_QUERY, $ircdata->nick, "Logged out");
        }
    }

    function Huhu(&$irc, &$ircdata) {
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $ircdata->messageex[0].' '.$ircdata->nick);
    }
    
    function Toblerone(&$irc, &$ircdata) {
        $nick = $this->_message_line($ircdata->message, $ircdata->nick);
        $irc->message(SMARTIRC_TYPE_ACTION, $ircdata->channel, 'gibt '.$nick.' eine Toblerone!');
    }

    function Say(&$irc, &$ircdata) {
        $message = $this->_message_line($ircdata->message);
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $message);
    }

    function SayMe(&$irc, &$ircdata) {
        $message = $this->_message_line($ircdata->message);
        $irc->message(SMARTIRC_TYPE_ACTION, $ircdata->channel, $message);
    }

    function Popp(&$irc, &$ircdata) {
        $nick = $this->_message_line($ircdata->message);
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, '*'.$nick.' anpopp* :o');
    }

    function Dice(&$irc, &$ircdata) {
        $max = isset($ircdata->messageex[1]) ? $ircdata->messageex[1] : 6;
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, '*wuerfel*');
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, rand(1, $max));
    }

    function EightBall(&$irc, &$ircdata) {
        $answers = array(
            'Soweit ich sehe, ja.',
            'Bestimmt.',
            'So ist es entschieden.',
            'Ziemlich wahrscheinlich.',
            'Sieht danach aus.',
            'Alle Anzeichen weisen darauf hin.',
            'Ohne Zweifel.',
            'Ja.',
            'Ja - definitiv.',
            'Darauf kannst du dich verlassen.',

            'Truebe Antwort, probier es nochmals.',
            'Frage nochmals.',
            'Sage ich dir besser noch nicht.',
            'Kann ich jetzt noch nicht sagen.',
            'Konzentriere dich und frage erneut.',

            'Damit kannst du nicht rechnen.',
            'Meine Antwort ist nein.',
            'Meine Quellen sagen nein.',
            'Sieht nicht so gut aus.',
            'Sehr zweifelhaft.',
        );

        if($ircdata->messageex[0] == "!8ball" && rand(0, 3) == 0 && $irc->isOpped($ircdata->channel)) {
            $irc->kick($ircdata->channel, $ircdata->nick, ':o');
        } else {
            $question = $this->_message_line($ircdata->message);
            $answer = IRC_BOLD.$answers[rand(0, count($answers)-1)];
            $msg = '<'.$ircdata->nick.'>'.(empty($question)?'':' '.$question).' '.$answer;
            $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $msg);
        }
    }

    function Ping(&$irc, &$ircdata) {
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, 'Pong!');
    }

    function Time(&$irc, &$ircdata) {
        $irc->message(
            SMARTIRC_TYPE_CHANNEL, $ircdata->channel,
            "Aktuelle Zeit auf dem Server: ".date("l, d. F, H:i:s")." Uhr"
        );
    }

    function simpleKeywords(&$irc, &$ircdata) {
        foreach($this->simpleKeywords as $keyword => $answer) {
            if(preg_match('/(^|\b)'.$keyword.'(\b|$)/i', $ircdata->message)) {
                $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $answer);
            }
        }
    }

    function searchEngine(&$irc, &$ircdata) {
        if(isset($this->searchEngines['!'.$ircdata->messageex[0]])) {
            $query = $this->searchEngines['!'.$ircdata->messageex[0]].
                                urlencode($this->_message_line($ircdata->message));
            $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, '-> '.$query);
        }
    } 

    function CHFtoEUR(&$irc, &$ircdata) {
        preg_match("/(?<value>[-+]?[0-9]*[.,]?[0-9]+)\s?chf/i", $ircdata->message, $value);
        $chf = strtr($value['value'], ',', '.');
        $context = stream_context_create(array('http' => array('timeout' => 1))); 
        $eur = file_get_contents(
            "http://www.multimolti.com/apps/currencyapi/calculator.php".
            "?original=CHF&target=EUR&value=".$chf,
            0,
            $context
        );
        if($eur !== false) {
            $msg = $chf.' CHF sind exakt '.number_format($eur, 2, ',', '.').' EUR';
        } else {
            $eur = $chf * 0.66; /* leider existiert kein offizieller Durchschnitt */
            $msg = $chf.' CHF sind ungefaehr '.number_format($eur, 2, ',', '.').' EUR';
        }
        $irc->message(SMARTIRC_TYPE_CHANNEL, $ircdata->channel, $msg);
    }

    function _message_line($message, $fallback = '') {
        @list($cmd, $line) = explode(' ', $message, 2);
        $line = (is_null($line)) ? $fallback : $line;
        return $line;
    }

}

//preg_match("/(?<value>[0-9]*(\.|,)?[0-9]+)\s?chf/i", "52 chf", $value);
//var_dump($value);
$T = new Tuersteherin();

?>
