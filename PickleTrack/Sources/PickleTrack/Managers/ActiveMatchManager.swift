import Foundation
import SwiftData

@Observable
final class ActiveMatchManager {
    var match: PBMatch?

    // Live serve state
    var servingTeam:   Int = 1
    var serverNumber:  Int = 2   // starts at 2 (first-serve rule)
    var isFirstServe:  Bool = true

    // Current game index
    var currentGameIndex: Int = 0

    var isActive: Bool { match != nil && !(match?.isComplete ?? true) }

    var currentGame: PBGame? {
        guard let games = match?.games.sorted(by: { $0.gameNumber < $1.gameNumber }),
              currentGameIndex < games.count else { return nil }
        return games[currentGameIndex]
    }

    var team1Score: Int { currentGame?.team1Score ?? 0 }
    var team2Score: Int { currentGame?.team2Score ?? 0 }

    // MARK: - Start

    func startMatch(_ match: PBMatch, context: ModelContext) {
        let firstGame = PBGame(gameNumber: 1)
        match.games.append(firstGame)
        context.insert(match)
        self.match       = match
        servingTeam      = 1
        serverNumber     = 2   // first-serve rule
        isFirstServe     = true
        currentGameIndex = 0
    }

    // MARK: - Score a point

    func scorePoint(team: Int, context: ModelContext) {
        guard let game = currentGame, let match else { return }

        let isServing = team == servingTeam

        // Update score
        if team == 1 { game.team1Score += 1 } else { game.team2Score += 1 }

        // Log the point
        var pts = game.points
        pts.append(PointEvent(
            scoringTeam:  team,
            serverTeam:   servingTeam,
            serverNumber: serverNumber,
            t1Score:      game.team1Score,
            t2Score:      game.team2Score
        ))
        game.points = pts

        // Advance serve state
        if !isServing {
            if match.format == .doubles && !isFirstServe && serverNumber == 1 {
                // Partner still has a serve
                serverNumber = 2
            } else {
                // Side-out
                servingTeam  = servingTeam == 1 ? 2 : 1
                serverNumber = 1
            }
            isFirstServe = false
        }

        // Check for game win
        let target = match.gameLength
        let t1 = game.team1Score; let t2 = game.team2Score
        let winByTwo = abs(t1 - t2) >= 2
        if (t1 >= target || t2 >= target) && winByTwo {
            game.winner = t1 > t2 ? 1 : 2
            advanceGame(context: context)
        }
    }

    // MARK: - Undo last point

    func undoLastPoint(context: ModelContext) {
        guard let game = currentGame else { return }
        var pts = game.points
        guard let last = pts.popLast() else { return }
        game.points   = pts
        game.team1Score = last.t1Score - (last.scoringTeam == 1 ? 1 : 0)
        game.team2Score = last.t2Score - (last.scoringTeam == 2 ? 1 : 0)
        game.winner   = 0
        // Restore serve state from second-to-last point
        if let prev = pts.last {
            servingTeam  = prev.serverTeam
            serverNumber = prev.serverNumber
        } else {
            servingTeam  = 1; serverNumber = 2; isFirstServe = true
        }
    }

    // MARK: - Private

    private func advanceGame(context: ModelContext) {
        guard let match else { return }
        let needed = (match.bestOf / 2) + 1
        if match.team1GamesWon >= needed || match.team2GamesWon >= needed {
            match.isComplete = true
            return
        }
        // Start next game — alternate who serves first
        let nextNum  = currentGameIndex + 2   // 1-based
        let nextGame = PBGame(gameNumber: nextNum)
        match.games.append(nextGame)
        currentGameIndex += 1
        // Loser of last game serves first in next game (common courtesy rule)
        servingTeam  = (currentGame?.winner == 1 ? 2 : 1)
        serverNumber = 1
        isFirstServe = false
    }

    func discardMatch(context: ModelContext) {
        if let m = match { context.delete(m) }
        reset()
    }

    func finishEarly(context: ModelContext) {
        match?.isComplete = true
        reset()
    }

    private func reset() {
        match            = nil
        servingTeam      = 1
        serverNumber     = 2
        isFirstServe     = true
        currentGameIndex = 0
    }

    // MARK: - Score announcement string (serving-receiving-server#)

    var announcement: String {
        guard let game = currentGame, let match else { return "" }
        let sScore = servingTeam == 1 ? game.team1Score : game.team2Score
        let rScore = servingTeam == 1 ? game.team2Score : game.team1Score
        if match.format == .doubles {
            return "\(sScore) - \(rScore) - \(serverNumber)"
        }
        return "\(sScore) - \(rScore)"
    }
}
