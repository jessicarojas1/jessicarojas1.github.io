import Foundation
import SwiftData

enum MatchFormat: String, Codable, CaseIterable {
    case singles = "Singles"
    case doubles = "Doubles"
}

enum GameLength: Int, Codable, CaseIterable, Identifiable {
    case short    = 11
    case medium   = 15
    case long     = 21
    var id: Int { rawValue }
    var label: String { "To \(rawValue)" }
}

// MARK: - Match

@Model
final class PBMatch {
    var date:         Date
    var format:       MatchFormat
    var gameLength:   Int            // 11, 15, or 21
    var bestOf:       Int            // 1, 3, or 5
    var team1Name:    String
    var team2Name:    String
    var team1Partner: String         // doubles partner (empty for singles)
    var team2Partner: String
    var isComplete:   Bool

    @Relationship(deleteRule: .cascade)
    var games: [PBGame] = []

    init(format: MatchFormat, gameLength: Int, bestOf: Int,
         team1Name: String, team1Partner: String,
         team2Name: String, team2Partner: String) {
        self.date         = .now
        self.format       = format
        self.gameLength   = gameLength
        self.bestOf       = bestOf
        self.team1Name    = team1Name
        self.team1Partner = team1Partner
        self.team2Name    = team2Name
        self.team2Partner = team2Partner
        self.isComplete   = false
    }

    var team1GamesWon: Int { games.filter { $0.winner == 1 }.count }
    var team2GamesWon: Int { games.filter { $0.winner == 2 }.count }

    var winner: Int? {
        guard isComplete else { return nil }
        return team1GamesWon > team2GamesWon ? 1 : 2
    }

    var displayName: String { "\(team1Name) vs \(team2Name)" }

    var team1DisplayName: String {
        format == .doubles && !team1Partner.isEmpty ? "\(team1Name) / \(team1Partner)" : team1Name
    }

    var team2DisplayName: String {
        format == .doubles && !team2Partner.isEmpty ? "\(team2Name) / \(team2Partner)" : team2Name
    }

    var scoreString: String {
        let sorted = games.sorted { $0.gameNumber < $1.gameNumber }
        return sorted.map { "\($0.team1Score)-\($0.team2Score)" }.joined(separator: ", ")
    }
}

// MARK: - Game

@Model
final class PBGame {
    var gameNumber:  Int
    var team1Score:  Int
    var team2Score:  Int
    var winner:      Int   // 1 or 2, 0 = in progress
    var pointsData:  Data  // [PointEvent] encoded

    init(gameNumber: Int) {
        self.gameNumber = gameNumber
        self.team1Score = 0
        self.team2Score = 0
        self.winner     = 0
        self.pointsData = Data()
    }

    var points: [PointEvent] {
        get { (try? JSONDecoder().decode([PointEvent].self, from: pointsData)) ?? [] }
        set { pointsData = (try? JSONEncoder().encode(newValue)) ?? Data() }
    }
}

// MARK: - Point event (audit trail for undo)

struct PointEvent: Codable {
    var scoringTeam:  Int   // 1 or 2
    var serverTeam:   Int
    var serverNumber: Int   // 1 or 2 (doubles)
    var t1Score:      Int   // score AFTER this point
    var t2Score:      Int
}
