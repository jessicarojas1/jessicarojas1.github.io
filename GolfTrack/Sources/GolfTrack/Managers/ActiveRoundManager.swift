import Foundation
import SwiftData

@Observable
final class ActiveRoundManager {
    var round: Round?
    var isActive: Bool { round != nil }

    // MARK: - Start / Finish

    func startRound(course: Course, tees: String = "White", context: ModelContext) {
        let r = Round(courseName: course.name, courseRating: course.rating,
                      slopeRating: course.slope, coursePar: course.par,
                      tees: tees, totalHoles: 18)
        for hole in course.holes {
            let hs = HoleScore(holeNumber: hole.number, par: hole.par, yardage: hole.yardage)
            r.holeScores.append(hs)
        }
        context.insert(r)
        round = r
    }

    func startCustomRound(name: String, rating: Double, slope: Int,
                          par: Int, holes: [HoleInfo], context: ModelContext) {
        let r = Round(courseName: name, courseRating: rating, slopeRating: slope,
                      coursePar: par, totalHoles: holes.count)
        for h in holes {
            let hs = HoleScore(holeNumber: h.number, par: h.par, yardage: h.yardage)
            r.holeScores.append(hs)
        }
        context.insert(r)
        round = r
    }

    /// Start a round from a CourseTemplate (e.g. from a nearby MapKit search result)
    func startRound(template: CourseTemplate, context: ModelContext) {
        startCustomRound(
            name:   template.name,
            rating: template.rating,
            slope:  template.slope,
            par:    template.totalPar,
            holes:  template.holes,
            context: context
        )
    }

    func finishRound() { round = nil }

    func discardRound(context: ModelContext) {
        if let r = round { context.delete(r) }
        round = nil
    }

    // MARK: - Score updates

    func setStrokes(_ strokes: Int, hole: HoleScore) {
        hole.strokes = max(1, strokes)
        updateGIR(hole: hole)
    }

    func setPutts(_ putts: Int, hole: HoleScore) {
        hole.putts = max(0, putts)
    }

    func toggleFairway(_ hole: HoleScore) {
        guard hole.fairwayHit != nil else { return }
        hole.fairwayHit = !(hole.fairwayHit ?? false)
    }

    func toggleGIR(_ hole: HoleScore) {
        hole.girHit.toggle()
    }

    private func updateGIR(hole: HoleScore) {
        // GIR = on green in par minus 2 or fewer strokes
        // Can't auto-calculate without shot-by-shot data; user toggles manually
    }

    // MARK: - Navigation helpers

    var currentHoleIndex: Int {
        guard let r = round else { return 0 }
        // Return first incomplete hole
        let sorted = r.sortedScores
        if let idx = sorted.firstIndex(where: { $0.strokes == $0.par && $0.putts == 2 }) {
            return idx
        }
        return sorted.count - 1
    }
}
