// GolfTrack — Garmin Connect IQ companion app (Monkey C)
// Displays current hole, par, score sent from the iPhone app via Bluetooth/Wi-Fi
// Requires: Garmin Connect IQ SDK 4.x, device with Connect IQ 3.4+

using Toybox.Application as App;
using Toybox.WatchUi as Ui;
using Toybox.Communications as Comm;
using Toybox.System as Sys;
using Toybox.Graphics as Gfx;

// ──────────────────────────────────────────────
// Application
// ──────────────────────────────────────────────
class GolfTrackApp extends App.AppBase {

    function initialize() {
        AppBase.initialize();
    }

    function getInitialView() {
        var view = new GolfTrackView();
        var delegate = new GolfTrackDelegate(view);
        return [view, delegate];
    }

    function onStart(state) {
        // Register for incoming phone messages
        Comm.registerForPhoneAppMessages(method(:onPhoneMessage));
    }

    function onPhoneMessage(msg) {
        // msg.data is a Dictionary sent from the iOS GolfTrack app
        if (msg.data instanceof Lang.Dictionary) {
            var data = msg.data;
            GolfState.holeNumber   = data.get("holeNumber")   != null ? data.get("holeNumber")   : GolfState.holeNumber;
            GolfState.par          = data.get("par")          != null ? data.get("par")           : GolfState.par;
            GolfState.yardage      = data.get("yardage")      != null ? data.get("yardage")       : GolfState.yardage;
            GolfState.strokes      = data.get("strokes")      != null ? data.get("strokes")       : GolfState.strokes;
            GolfState.putts        = data.get("putts")        != null ? data.get("putts")         : GolfState.putts;
            GolfState.scoreVsPar   = data.get("scoreVsPar")   != null ? data.get("scoreVsPar")    : GolfState.scoreVsPar;
            GolfState.totalStrokes = data.get("totalStrokes") != null ? data.get("totalStrokes")  : GolfState.totalStrokes;
            GolfState.courseName   = data.get("courseName")   != null ? data.get("courseName")    : GolfState.courseName;
            Ui.requestUpdate();
        }
    }
}

// ──────────────────────────────────────────────
// Shared state
// ──────────────────────────────────────────────
module GolfState {
    var holeNumber   = 0;
    var par          = 4;
    var yardage      = 0;
    var strokes      = 4;
    var putts        = 2;
    var scoreVsPar   = 0;
    var totalStrokes = 0;
    var courseName   = "GolfTrack";
}

// ──────────────────────────────────────────────
// View
// ──────────────────────────────────────────────
class GolfTrackView extends Ui.View {

    function initialize() {
        View.initialize();
    }

    function onUpdate(dc) {
        var w = dc.getWidth();
        var h = dc.getHeight();
        dc.setColor(Gfx.COLOR_BLACK, Gfx.COLOR_BLACK);
        dc.clear();

        if (GolfState.holeNumber == 0) {
            // Idle screen
            dc.setColor(Gfx.COLOR_WHITE, Gfx.COLOR_TRANSPARENT);
            dc.drawText(w / 2, h / 2 - 20, Gfx.FONT_MEDIUM, "GolfTrack", Gfx.TEXT_JUSTIFY_CENTER);
            dc.setColor(Gfx.COLOR_LT_GRAY, Gfx.COLOR_TRANSPARENT);
            dc.drawText(w / 2, h / 2 + 10, Gfx.FONT_TINY, "Open iPhone app", Gfx.TEXT_JUSTIFY_CENTER);
            dc.drawText(w / 2, h / 2 + 28, Gfx.FONT_TINY, "to start a round", Gfx.TEXT_JUSTIFY_CENTER);
            return;
        }

        // Header: course name
        dc.setColor(Gfx.COLOR_LT_GRAY, Gfx.COLOR_TRANSPARENT);
        var displayName = GolfState.courseName.length() > 16
            ? GolfState.courseName.substring(0, 15) + "…"
            : GolfState.courseName;
        dc.drawText(w / 2, 10, Gfx.FONT_TINY, displayName, Gfx.TEXT_JUSTIFY_CENTER);

        // Hole number (large, centered)
        dc.setColor(Gfx.COLOR_WHITE, Gfx.COLOR_TRANSPARENT);
        dc.drawText(w / 2, h / 2 - 30, Gfx.FONT_NUMBER_HOT, GolfState.holeNumber.toString(), Gfx.TEXT_JUSTIFY_CENTER);

        // Par and yardage
        dc.setColor(Gfx.COLOR_LT_GRAY, Gfx.COLOR_TRANSPARENT);
        var parYds = "Par " + GolfState.par.toString();
        if (GolfState.yardage > 0) {
            parYds = parYds + "  ·  " + GolfState.yardage.toString() + " yds";
        }
        dc.drawText(w / 2, h / 2 + 10, Gfx.FONT_SMALL, parYds, Gfx.TEXT_JUSTIFY_CENTER);

        // Score vs par badge
        var svpColor = scoreColor(GolfState.scoreVsPar);
        var svpText  = scoreLabel(GolfState.scoreVsPar);
        dc.setColor(svpColor, Gfx.COLOR_TRANSPARENT);
        dc.drawText(w / 2, h / 2 + 34, Gfx.FONT_MEDIUM, svpText, Gfx.TEXT_JUSTIFY_CENTER);

        // Bottom: strokes / putts
        dc.setColor(Gfx.COLOR_LT_GRAY, Gfx.COLOR_TRANSPARENT);
        var bottomText = "Strokes: " + GolfState.strokes.toString() + "  Putts: " + GolfState.putts.toString();
        dc.drawText(w / 2, h - 26, Gfx.FONT_TINY, bottomText, Gfx.TEXT_JUSTIFY_CENTER);
    }

    function scoreColor(svp) {
        if (svp <= -1) { return Gfx.COLOR_YELLOW; }
        if (svp == 0)  { return Gfx.COLOR_GREEN;  }
        if (svp == 1)  { return Gfx.COLOR_BLUE;   }
        return Gfx.COLOR_RED;
    }

    function scoreLabel(svp) {
        if (svp == 0) { return "E"; }
        if (svp > 0)  { return "+" + svp.toString(); }
        return svp.toString();
    }
}

// ──────────────────────────────────────────────
// Input delegate — Up/Down to navigate holes, Select to send next hole
// ──────────────────────────────────────────────
class GolfTrackDelegate extends Ui.BehaviorDelegate {
    var _view;

    function initialize(view) {
        BehaviorDelegate.initialize();
        _view = view;
    }

    function onSelect() {
        // Send "nextHole" action back to iPhone
        var msg = { "action" => "nextHole" };
        Comm.transmit(msg, null, null);
        return true;
    }

    function onBack() {
        var msg = { "action" => "prevHole" };
        Comm.transmit(msg, null, null);
        return true;
    }
}
