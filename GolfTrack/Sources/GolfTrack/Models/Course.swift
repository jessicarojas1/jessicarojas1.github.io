import Foundation
import SwiftData

// MARK: - Hole definition (value type, not persisted separately)

struct HoleInfo: Codable, Identifiable {
    var id: Int { number }
    let number: Int
    let par: Int
    let yardage: Int        // from standard tee
    let handicapIndex: Int  // stroke index 1-18
}

// MARK: - Built-in course (not persisted)

struct Course: Identifiable, Hashable {
    let id: String
    let name: String
    let location: String
    let par: Int
    let rating: Double   // Course Rating
    let slope: Int       // Slope Rating (55-155, standard 113)
    let holes: [HoleInfo]

    var totalYardage: Int { holes.reduce(0) { $0 + $1.yardage } }
}

// MARK: - User-created custom course (persisted)

@Model final class CustomCourse {
    var id: UUID = UUID()
    var name: String = ""
    var location: String = ""
    var rating: Double = 72.0
    var slope: Int = 113
    var par: Int = 72
    // Stored as JSON-encoded [HoleInfo]
    var holesData: Data = Data()

    init(name: String, location: String = "", rating: Double = 72.0, slope: Int = 113) {
        self.id       = UUID()
        self.name     = name
        self.location = location
        self.rating   = rating
        self.slope    = slope
        self.holesData = Self.defaultHolesData()
    }

    var holes: [HoleInfo] {
        get { (try? JSONDecoder().decode([HoleInfo].self, from: holesData)) ?? [] }
        set { holesData = (try? JSONEncoder().encode(newValue)) ?? Data() }
    }

    var totalPar: Int { holes.reduce(0) { $0 + $1.par } }

    private static func defaultHolesData() -> Data {
        let defaults: [HoleInfo] = (1...18).map { n in
            HoleInfo(number: n, par: n % 3 == 0 ? 5 : (n % 3 == 1 ? 4 : 3),
                     yardage: 400, handicapIndex: n)
        }
        return (try? JSONEncoder().encode(defaults)) ?? Data()
    }
}
