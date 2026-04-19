import Foundation
import SwiftData

// MARK: - Round

@Model final class Round {
    var id: UUID = UUID()
    var date: Date = Date.now
    var courseName: String = ""
    var courseRating: Double = 72.0
    var slopeRating: Int = 113
    var coursePar: Int = 72
    var tees: String = "White"
    var totalHoles: Int = 18    // 9 or 18
    var notes: String = ""
    @Relationship(deleteRule: .cascade) var holeScores: [HoleScore] = []

    init(courseName: String, courseRating: Double, slopeRating: Int,
         coursePar: Int, tees: String = "White", totalHoles: Int = 18) {
        self.id           = UUID()
        self.date         = .now
        self.courseName   = courseName
        self.courseRating = courseRating
        self.slopeRating  = slopeRating
        self.coursePar    = coursePar
        self.tees         = tees
        self.totalHoles   = totalHoles
    }

    // MARK: Computed stats

    var sortedScores: [HoleScore] { holeScores.sorted { $0.holeNumber < $1.holeNumber } }

    var totalStrokes: Int { holeScores.reduce(0) { $0 + $1.strokes } }
    var totalPutts: Int   { holeScores.reduce(0) { $0 + $1.putts } }

    var scoreVsPar: Int { totalStrokes - coursePar }

    /// World Handicap System score differential
    var scoreDifferential: Double {
        guard totalStrokes > 0 else { return 0 }
        return (Double(totalStrokes) - courseRating) * 113.0 / Double(slopeRating)
    }

    var fairwaysHit: Int    { holeScores.filter { $0.fairwayHit == true }.count }
    var fairwayHoles: Int   { holeScores.filter { $0.fairwayHit != nil }.count }
    var fairwayPct: Double  { fairwayHoles > 0 ? Double(fairwaysHit) / Double(fairwayHoles) * 100 : 0 }

    var greensInReg: Int    { holeScores.filter { $0.girHit }.count }
    var girPct: Double      { holeScores.isEmpty ? 0 : Double(greensInReg) / Double(holeScores.count) * 100 }

    var averagePutts: Double {
        guard !holeScores.isEmpty else { return 0 }
        return Double(totalPutts) / Double(holeScores.count)
    }

    var holesCompleted: Int { holeScores.count }
    var isComplete: Bool    { holesCompleted >= totalHoles }

    var scoreLabel: String {
        let diff = scoreVsPar
        if diff == 0 { return "E" }
        return diff > 0 ? "+\(diff)" : "\(diff)"
    }

    var formattedDate: String {
        date.formatted(date: .abbreviated, time: .omitted)
    }
}

// MARK: - HoleScore

@Model final class HoleScore {
    var holeNumber: Int = 1
    var par: Int = 4
    var yardage: Int = 400
    var strokes: Int = 0
    var putts: Int = 2
    var fairwayHit: Bool? = nil   // nil = par 3 (no fairway)
    var girHit: Bool = false
    var penaltyStrokes: Int = 0
    var sand: Bool = false        // hit bunker?
    var sandSave: Bool = false    // got up and down from bunker?
    var round: Round?

    init(holeNumber: Int, par: Int, yardage: Int = 0) {
        self.holeNumber = holeNumber
        self.par        = par
        self.yardage    = yardage
        self.strokes    = par   // default to par
        self.fairwayHit = par == 3 ? nil : false
    }

    var scoreVsPar: Int { strokes - par }

    var scoreName: String {
        switch scoreVsPar {
        case ..<(-2): return "Albatross"
        case -2:      return "Eagle"
        case -1:      return "Birdie"
        case 0:       return "Par"
        case 1:       return "Bogey"
        case 2:       return "Double"
        case 3:       return "Triple"
        default:      return "+\(scoreVsPar)"
        }
    }

    var scoreColor: String {
        switch scoreVsPar {
        case ..<0:  return "yellow"
        case 0:     return "green"
        case 1:     return "primary"
        default:    return "red"
        }
    }
}
