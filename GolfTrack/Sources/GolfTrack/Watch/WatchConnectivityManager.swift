import Foundation
import WatchConnectivity

// Shared message keys
enum WCKey {
    static let holeNumber   = "holeNumber"
    static let par          = "par"
    static let yardage      = "yardage"
    static let strokes      = "strokes"
    static let putts        = "putts"
    static let totalStrokes = "totalStrokes"
    static let scoreVsPar   = "scoreVsPar"
    static let courseName   = "courseName"
    static let holesTotal   = "holesTotal"
    static let holesPlayed  = "holesPlayed"
    static let action       = "action"
    static let scoreUpdate  = "scoreUpdate"
    static let puttUpdate   = "puttUpdate"
}

extension Notification.Name {
    static let watchScoreUpdate  = Notification.Name("watchScoreUpdate")
    static let watchPuttUpdate   = Notification.Name("watchPuttUpdate")
    static let watchNextHole     = Notification.Name("watchNextHole")
    static let watchPrevHole     = Notification.Name("watchPrevHole")
}

@Observable
final class WatchConnectivityManager: NSObject, WCSessionDelegate {
    static let shared = WatchConnectivityManager()

    var isPaired      = false
    var isReachable   = false
    var isInstalled   = false

    override init() {
        super.init()
        guard WCSession.isSupported() else { return }
        WCSession.default.delegate = self
        WCSession.default.activate()
    }

    // MARK: - Send to Watch

    /// Push active hole info to Apple Watch
    func sendHoleUpdate(hole: HoleScore, round: Round) {
        guard WCSession.default.isReachable else { return }
        let msg: [String: Any] = [
            WCKey.holeNumber:   hole.holeNumber,
            WCKey.par:          hole.par,
            WCKey.yardage:      hole.yardage,
            WCKey.strokes:      hole.strokes,
            WCKey.putts:        hole.putts,
            WCKey.totalStrokes: round.totalStrokes,
            WCKey.scoreVsPar:   round.scoreVsPar,
            WCKey.courseName:   round.courseName,
            WCKey.holesTotal:   round.totalHoles,
            WCKey.holesPlayed:  round.holesCompleted,
        ]
        WCSession.default.sendMessage(msg, replyHandler: nil) { error in
            print("WC send error: \(error)")
        }
    }

    /// Update Watch complication / context (persists when watch is not reachable)
    func updateApplicationContext(round: Round?) {
        guard WCSession.isSupported() else { return }
        var ctx: [String: Any] = [:]
        if let r = round {
            ctx[WCKey.courseName]   = r.courseName
            ctx[WCKey.totalStrokes] = r.totalStrokes
            ctx[WCKey.scoreVsPar]   = r.scoreVsPar
            ctx[WCKey.holesPlayed]  = r.holesCompleted
            ctx[WCKey.holesTotal]   = r.totalHoles
        }
        try? WCSession.default.updateApplicationContext(ctx)
    }

    // MARK: - Receive from Watch

    func session(_ session: WCSession, didReceiveMessage message: [String: Any]) {
        DispatchQueue.main.async {
            let action = message[WCKey.action] as? String ?? ""
            switch action {
            case WCKey.scoreUpdate:
                NotificationCenter.default.post(name: .watchScoreUpdate, object: nil, userInfo: message)
            case WCKey.puttUpdate:
                NotificationCenter.default.post(name: .watchPuttUpdate, object: nil, userInfo: message)
            case "nextHole":
                NotificationCenter.default.post(name: .watchNextHole, object: nil)
            case "prevHole":
                NotificationCenter.default.post(name: .watchPrevHole, object: nil)
            default:
                break
            }
        }
    }

    // MARK: - Required delegate methods

    func session(_ session: WCSession,
                 activationDidCompleteWith state: WCSessionActivationState,
                 error: Error?) {
        DispatchQueue.main.async {
            self.isPaired    = session.isPaired
            self.isInstalled = session.isWatchAppInstalled
        }
    }

    func sessionReachabilityDidChange(_ session: WCSession) {
        DispatchQueue.main.async { self.isReachable = session.isReachable }
    }

    // macOS/iOS split
    #if os(iOS)
    func sessionDidBecomeInactive(_ session: WCSession) {}
    func sessionDidDeactivate(_ session: WCSession) {
        WCSession.default.activate()
    }
    #endif
}
