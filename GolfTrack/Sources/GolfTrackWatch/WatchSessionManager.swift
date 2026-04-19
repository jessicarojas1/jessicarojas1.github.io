import Foundation
import WatchConnectivity

@Observable
final class WatchSessionManager: NSObject, WCSessionDelegate {

    var holeNumber   = 0
    var par          = 4
    var yardage      = 0
    var strokes      = 4
    var putts        = 2
    var totalStrokes = 0
    var scoreVsPar   = 0
    var courseName   = ""
    var holesPlayed  = 0
    var holesTotal   = 18
    var isRoundActive = false

    override init() {
        super.init()
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    // MARK: - Send score back to iPhone

    func sendStrokeUpdate(strokes: Int) {
        send([
            "action":        "scoreUpdate",
            "holeNumber":    holeNumber,
            "strokes":       strokes
        ])
    }

    func sendPuttUpdate(putts: Int) {
        send([
            "action":     "puttUpdate",
            "holeNumber": holeNumber,
            "putts":      putts
        ])
    }

    func sendNextHole() { send(["action": "nextHole"]) }
    func sendPrevHole() { send(["action": "prevHole"]) }

    private func send(_ msg: [String: Any]) {
        guard WCSession.default.isReachable else { return }
        WCSession.default.sendMessage(msg, replyHandler: nil)
    }

    // MARK: - Receive from iPhone

    func session(_ session: WCSession, didReceiveMessage message: [String: Any]) {
        DispatchQueue.main.async {
            if let v = message["holeNumber"]   as? Int    { self.holeNumber   = v }
            if let v = message["par"]          as? Int    { self.par          = v }
            if let v = message["yardage"]      as? Int    { self.yardage      = v }
            if let v = message["strokes"]      as? Int    { self.strokes      = v }
            if let v = message["putts"]        as? Int    { self.putts        = v }
            if let v = message["totalStrokes"] as? Int    { self.totalStrokes = v }
            if let v = message["scoreVsPar"]   as? Int    { self.scoreVsPar   = v }
            if let v = message["courseName"]   as? String { self.courseName   = v }
            if let v = message["holesPlayed"]  as? Int    { self.holesPlayed  = v }
            if let v = message["holesTotal"]   as? Int    { self.holesTotal   = v }
            self.isRoundActive = self.holeNumber > 0
        }
    }

    func session(_ session: WCSession, didReceiveApplicationContext ctx: [String: Any]) {
        DispatchQueue.main.async {
            if let v = ctx["courseName"]   as? String { self.courseName   = v }
            if let v = ctx["totalStrokes"] as? Int    { self.totalStrokes = v }
            if let v = ctx["scoreVsPar"]   as? Int    { self.scoreVsPar   = v }
            if let v = ctx["holesPlayed"]  as? Int    { self.holesPlayed  = v }
            if let v = ctx["holesTotal"]   as? Int    { self.holesTotal   = v }
        }
    }

    func session(_ session: WCSession,
                 activationDidCompleteWith state: WCSessionActivationState,
                 error: Error?) {}
}
