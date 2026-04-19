import Foundation

enum Club: String, CaseIterable, Identifiable, Codable {
    case driver  = "Driver"
    case wood3   = "3-Wood"
    case wood5   = "5-Wood"
    case hybrid4 = "4-Hybrid"
    case iron4   = "4-Iron"
    case iron5   = "5-Iron"
    case iron6   = "6-Iron"
    case iron7   = "7-Iron"
    case iron8   = "8-Iron"
    case iron9   = "9-Iron"
    case pw      = "PW"
    case gw      = "GW"
    case sw      = "SW"
    case lw      = "LW"

    var id: String { rawValue }

    var emoji: String {
        switch self {
        case .driver:           return "🏌️"
        case .wood3, .wood5:    return "🪵"
        case .hybrid4:          return "⚙️"
        case .iron4, .iron5,
             .iron6, .iron7,
             .iron8, .iron9:   return "🔩"
        case .pw, .gw, .sw, .lw: return "🥏"
        }
    }
}

enum SkillLevel: String, CaseIterable, Identifiable {
    case beginner     = "Beginner"
    case intermediate = "Intermediate"
    case advanced     = "Advanced"
    case masters      = "Masters"

    var id: String { rawValue }

    var description: String {
        switch self {
        case .beginner:     return "Avg score 100+"
        case .intermediate: return "Avg score 85–99"
        case .advanced:     return "Avg score 75–84"
        case .masters:      return "Scratch / low handicap"
        }
    }

    /// Typical carry distances in yards for each club
    var distances: [Club: Int] {
        switch self {
        case .beginner:
            return [
                .driver: 150, .wood3: 135, .wood5: 125, .hybrid4: 115,
                .iron4: 105, .iron5: 95, .iron6: 85, .iron7: 80,
                .iron8: 70,  .iron9: 65, .pw: 55, .gw: 50, .sw: 40, .lw: 35
            ]
        case .intermediate:
            return [
                .driver: 200, .wood3: 180, .wood5: 165, .hybrid4: 155,
                .iron4: 145, .iron5: 135, .iron6: 125, .iron7: 115,
                .iron8: 105, .iron9: 95, .pw: 85, .gw: 75, .sw: 60, .lw: 50
            ]
        case .advanced:
            return [
                .driver: 230, .wood3: 210, .wood5: 195, .hybrid4: 180,
                .iron4: 170, .iron5: 160, .iron6: 150, .iron7: 140,
                .iron8: 130, .iron9: 120, .pw: 110, .gw: 100, .sw: 85, .lw: 70
            ]
        case .masters:
            return [
                .driver: 270, .wood3: 245, .wood5: 225, .hybrid4: 215,
                .iron4: 200, .iron5: 185, .iron6: 175, .iron7: 165,
                .iron8: 155, .iron9: 140, .pw: 130, .gw: 115, .sw: 100, .lw: 85
            ]
        }
    }
}

@Observable
final class ClubProfile {
    static let shared = ClubProfile()

    var useCustomDistances: Bool {
        didSet { save() }
    }
    var selectedLevel: SkillLevel {
        didSet { save() }
    }
    var customDistances: [Club: Int] {
        didSet { save() }
    }

    private init() {
        let defaults = UserDefaults.standard
        useCustomDistances = defaults.bool(forKey: "clubProfile.useCustom")
        selectedLevel = SkillLevel(rawValue: defaults.string(forKey: "clubProfile.level") ?? "") ?? .intermediate

        if let data = defaults.data(forKey: "clubProfile.custom"),
           let decoded = try? JSONDecoder().decode([String: Int].self, from: data) {
            var map: [Club: Int] = [:]
            for club in Club.allCases {
                map[club] = decoded[club.rawValue] ?? SkillLevel.intermediate.distances[club] ?? 100
            }
            customDistances = map
        } else {
            customDistances = SkillLevel.intermediate.distances
        }
    }

    var activeDistances: [Club: Int] {
        useCustomDistances ? customDistances : selectedLevel.distances
    }

    /// Returns the best club(s) for a given yardage.
    /// Primary: shortest club that fully reaches the yardage.
    /// Also returns a ±1 alternative.
    func recommendations(for yardage: Int) -> [ClubRecommendation] {
        guard yardage > 0 else { return [] }

        // Sort clubs by distance descending
        let sorted = Club.allCases.compactMap { club -> (Club, Int)? in
            guard let dist = activeDistances[club] else { return nil }
            return (club, dist)
        }.sorted { $0.1 > $1.1 }

        // Find the first club whose distance <= yardage (i.e., doesn't overshoot) — closest to target
        // Strategy: club whose distance is ≥ yardage (use the shortest such club)
        let reaches = sorted.filter { $0.1 >= yardage }
        let primary: (Club, Int)? = reaches.last  // shortest club that still reaches
        let shortBy: (Club, Int)? = sorted.first(where: { $0.1 < yardage })  // longest club that falls short

        var results: [ClubRecommendation] = []
        if let p = primary {
            let delta = p.1 - yardage
            results.append(ClubRecommendation(club: p.0, distance: p.1, delta: delta, role: .primary))
        }
        if let s = shortBy {
            let delta = s.1 - yardage
            results.append(ClubRecommendation(club: s.0, distance: s.1, delta: delta, role: .secondary))
        }
        return results
    }

    private func save() {
        let defaults = UserDefaults.standard
        defaults.set(useCustomDistances, forKey: "clubProfile.useCustom")
        defaults.set(selectedLevel.rawValue, forKey: "clubProfile.level")
        let encoded = Dictionary(uniqueKeysWithValues: customDistances.map { ($0.key.rawValue, $0.value) })
        if let data = try? JSONEncoder().encode(encoded) {
            defaults.set(data, forKey: "clubProfile.custom")
        }
    }
}

struct ClubRecommendation: Identifiable {
    enum Role { case primary, secondary }
    let id = UUID()
    let club: Club
    let distance: Int
    let delta: Int      // positive = over, negative = short
    let role: Role
}
