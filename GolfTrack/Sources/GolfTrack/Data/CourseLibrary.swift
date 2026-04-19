import Foundation

final class CourseLibrary {
    static let shared = CourseLibrary()
    private init() {}

    lazy var featured: [Course] = [
        Course(id: "pebble_beach", name: "Pebble Beach Golf Links",
               location: "Pebble Beach, CA", par: 72, rating: 75.5, slope: 145,
               holes: pebbleBeachHoles),

        Course(id: "tpc_sawgrass", name: "TPC Sawgrass (Stadium)",
               location: "Ponte Vedra Beach, FL", par: 72, rating: 74.7, slope: 146,
               holes: tpcSawgrassHoles),

        Course(id: "bethpage_black", name: "Bethpage Black",
               location: "Farmingdale, NY", par: 71, rating: 78.0, slope: 155,
               holes: bethpageBlackHoles),

        Course(id: "torrey_pines_south", name: "Torrey Pines South",
               location: "La Jolla, CA", par: 72, rating: 75.5, slope: 144,
               holes: torreyPinesHoles),

        Course(id: "generic_muni", name: "Generic Municipal Course",
               location: "Anywhere, USA", par: 72, rating: 69.5, slope: 113,
               holes: genericMuniHoles),
    ]

    func course(id: String) -> Course? { featured.first { $0.id == id } }

    // MARK: - Hole data

    private var pebbleBeachHoles: [HoleInfo] { [
        HoleInfo(number:1,  par:4, yardage:377, handicapIndex:11),
        HoleInfo(number:2,  par:5, yardage:502, handicapIndex:9),
        HoleInfo(number:3,  par:4, yardage:390, handicapIndex:15),
        HoleInfo(number:4,  par:4, yardage:331, handicapIndex:17),
        HoleInfo(number:5,  par:3, yardage:195, handicapIndex:13),
        HoleInfo(number:6,  par:5, yardage:523, handicapIndex:5),
        HoleInfo(number:7,  par:3, yardage:109, handicapIndex:18),
        HoleInfo(number:8,  par:4, yardage:431, handicapIndex:1),
        HoleInfo(number:9,  par:4, yardage:481, handicapIndex:3),
        HoleInfo(number:10, par:4, yardage:446, handicapIndex:2),
        HoleInfo(number:11, par:4, yardage:380, handicapIndex:12),
        HoleInfo(number:12, par:3, yardage:202, handicapIndex:16),
        HoleInfo(number:13, par:4, yardage:400, handicapIndex:10),
        HoleInfo(number:14, par:5, yardage:580, handicapIndex:6),
        HoleInfo(number:15, par:4, yardage:397, handicapIndex:14),
        HoleInfo(number:16, par:4, yardage:403, handicapIndex:8),
        HoleInfo(number:17, par:3, yardage:208, handicapIndex:4),
        HoleInfo(number:18, par:5, yardage:543, handicapIndex:7),
    ] }

    private var tpcSawgrassHoles: [HoleInfo] { [
        HoleInfo(number:1,  par:4, yardage:423, handicapIndex:9),
        HoleInfo(number:2,  par:5, yardage:532, handicapIndex:13),
        HoleInfo(number:3,  par:3, yardage:177, handicapIndex:17),
        HoleInfo(number:4,  par:4, yardage:384, handicapIndex:7),
        HoleInfo(number:5,  par:4, yardage:466, handicapIndex:1),
        HoleInfo(number:6,  par:4, yardage:393, handicapIndex:11),
        HoleInfo(number:7,  par:4, yardage:442, handicapIndex:3),
        HoleInfo(number:8,  par:3, yardage:219, handicapIndex:15),
        HoleInfo(number:9,  par:5, yardage:583, handicapIndex:5),
        HoleInfo(number:10, par:4, yardage:424, handicapIndex:10),
        HoleInfo(number:11, par:5, yardage:558, handicapIndex:14),
        HoleInfo(number:12, par:4, yardage:358, handicapIndex:18),
        HoleInfo(number:13, par:3, yardage:181, handicapIndex:16),
        HoleInfo(number:14, par:4, yardage:467, handicapIndex:2),
        HoleInfo(number:15, par:4, yardage:449, handicapIndex:6),
        HoleInfo(number:16, par:5, yardage:523, handicapIndex:12),
        HoleInfo(number:17, par:3, yardage:137, handicapIndex:8),  // island green
        HoleInfo(number:18, par:4, yardage:462, handicapIndex:4),
    ] }

    private var bethpageBlackHoles: [HoleInfo] { [
        HoleInfo(number:1,  par:4, yardage:430, handicapIndex:5),
        HoleInfo(number:2,  par:4, yardage:389, handicapIndex:15),
        HoleInfo(number:3,  par:3, yardage:230, handicapIndex:9),
        HoleInfo(number:4,  par:5, yardage:517, handicapIndex:11),
        HoleInfo(number:5,  par:4, yardage:478, handicapIndex:1),
        HoleInfo(number:6,  par:4, yardage:408, handicapIndex:13),
        HoleInfo(number:7,  par:5, yardage:525, handicapIndex:7),
        HoleInfo(number:8,  par:3, yardage:210, handicapIndex:17),
        HoleInfo(number:9,  par:4, yardage:459, handicapIndex:3),
        HoleInfo(number:10, par:4, yardage:492, handicapIndex:2),
        HoleInfo(number:11, par:4, yardage:435, handicapIndex:10),
        HoleInfo(number:12, par:4, yardage:499, handicapIndex:4),
        HoleInfo(number:13, par:5, yardage:565, handicapIndex:8),
        HoleInfo(number:14, par:3, yardage:161, handicapIndex:18),
        HoleInfo(number:15, par:4, yardage:459, handicapIndex:6),
        HoleInfo(number:16, par:3, yardage:207, handicapIndex:16),
        HoleInfo(number:17, par:4, yardage:490, handicapIndex:12),
        HoleInfo(number:18, par:4, yardage:411, handicapIndex:14),
    ] }

    private var torreyPinesHoles: [HoleInfo] { [
        HoleInfo(number:1,  par:4, yardage:452, handicapIndex:11),
        HoleInfo(number:2,  par:4, yardage:389, handicapIndex:15),
        HoleInfo(number:3,  par:3, yardage:198, handicapIndex:17),
        HoleInfo(number:4,  par:4, yardage:490, handicapIndex:1),
        HoleInfo(number:5,  par:4, yardage:453, handicapIndex:5),
        HoleInfo(number:6,  par:4, yardage:513, handicapIndex:3),
        HoleInfo(number:7,  par:3, yardage:168, handicapIndex:13),
        HoleInfo(number:8,  par:5, yardage:570, handicapIndex:9),
        HoleInfo(number:9,  par:4, yardage:453, handicapIndex:7),
        HoleInfo(number:10, par:4, yardage:409, handicapIndex:12),
        HoleInfo(number:11, par:3, yardage:221, handicapIndex:16),
        HoleInfo(number:12, par:4, yardage:504, handicapIndex:2),
        HoleInfo(number:13, par:4, yardage:453, handicapIndex:6),
        HoleInfo(number:14, par:5, yardage:614, handicapIndex:4),
        HoleInfo(number:15, par:4, yardage:448, handicapIndex:8),
        HoleInfo(number:16, par:3, yardage:227, handicapIndex:18),
        HoleInfo(number:17, par:4, yardage:424, handicapIndex:14),
        HoleInfo(number:18, par:5, yardage:570, handicapIndex:10),
    ] }

    private var genericMuniHoles: [HoleInfo] { (1...18).map { n in
        let pars = [4,4,3,5,4,3,4,5,4, 4,3,4,5,4,3,4,4,5]
        let yards = [380,390,165,510,400,150,420,490,370, 360,175,395,520,410,155,385,405,500]
        return HoleInfo(number: n, par: pars[n-1], yardage: yards[n-1], handicapIndex: n)
    } }
}
