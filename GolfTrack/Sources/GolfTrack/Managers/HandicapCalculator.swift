import Foundation

enum HandicapCalculator {

    /// World Handicap System: number of differentials to use based on rounds played
    static func handicapIndex(from rounds: [Round]) -> Double? {
        let diffs = rounds
            .filter { $0.isComplete && $0.totalStrokes > 0 }
            .sorted { $0.date > $1.date }
            .prefix(20)
            .map { $0.scoreDifferential }

        guard diffs.count >= 3 else { return nil }

        let count  = diffs.count
        let useCount: Int
        switch count {
        case 3...4:   useCount = 1
        case 5...6:   useCount = 2
        case 7...8:   useCount = 2
        case 9...10:  useCount = 3
        case 11...12: useCount = 4
        case 13...14: useCount = 5
        case 15...16: useCount = 6
        case 17:      useCount = 7
        default:      useCount = 8
        }

        let best = diffs.sorted().prefix(useCount)
        let avg  = best.reduce(0, +) / Double(useCount)
        return (avg * 0.96 * 10).rounded() / 10   // one decimal place
    }

    /// Course Handicap from a Handicap Index for a specific course
    static func courseHandicap(index: Double, slope: Int, rating: Double, par: Int) -> Int {
        let ch = index * (Double(slope) / 113.0) + (rating - Double(par))
        return Int(ch.rounded())
    }

    /// Net score after applying course handicap
    static func netScore(grossStrokes: Int, courseHandicap: Int) -> Int {
        grossStrokes - courseHandicap
    }
}
